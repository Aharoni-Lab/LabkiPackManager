// Minimal Phase 3 UI: Tree + SelectionModel + Summary
(function(){
	const state = {
		data: null,
		expanded: Object.create(null),
		selected: Object.create(null),
		locks: Object.create(null),
		repo: null,
		lastSources: [],
		previewPacksSet: new Set(),
		previewPagesSet: new Set(),
		uids: 0,
		uidPrefix: (Math.random().toString(36).slice(2)),
		mermaidReady: false,
		resolverOpen: false,
		planDraft: { globalPrefix: '', pages: {} },
		lastPlan: null
	};

	// Restore last used global prefix from localStorage
	try {
		const gp = localStorage.getItem('lpm_global_prefix');
		if (typeof gp === 'string' && gp) { state.planDraft.globalPrefix = gp; }
	} catch(e) {}

	function isExpanded(id){
		return state.expanded[id] !== undefined ? state.expanded[id] : true;
	}

	function apiUrl(selected, opts){
		let base = mw.util.wikiScript('api') + '?action=labkipacks&format=json&formatversion=2';
		if (state.repo) base += '&repo=' + encodeURIComponent(state.repo);
		if (opts && opts.refresh) base += '&refresh=1';
		if (opts && opts.plan){ try { base += '&plan=' + encodeURIComponent(JSON.stringify(opts.plan)); } catch(e){} }
		if (!selected || !selected.length) return base;
		return base + selected.map(s => '&selected[]=' + encodeURIComponent(s)).join('');
	}

	async function fetchData(opts){
		const url = apiUrl(Object.keys(state.selected), opts);
		try {
			const res = await fetch(url, { credentials: 'same-origin' });
			if (!res.ok) throw new Error('HTTP ' + res.status);
			const json = await res.json();
			state.data = json.labkipacks || json;
			state.repo = state.data?.source?.name || state.repo;
			if (Array.isArray(state.data?.sources)) state.lastSources = state.data.sources.slice();
			state.locks = Object.create(null);
			if (state.data.preview && state.data.preview.locks){
				for (const [k,v] of Object.entries(state.data.preview.locks)) state.locks[k] = v;
			}
			state.previewPacksSet = new Set(Array.isArray(state.data?.preview?.packs) ? state.data.preview.packs : []);
			state.previewPagesSet = new Set(Array.isArray(state.data?.preview?.pages) ? state.data.preview.pages : []);
			state.lastPlan = state.data?.plan || null;
			render();
		} catch (e) {
			mw.notify('Failed to load data: ' + e, { type: 'error' });
		}
	}

	function toggleExpand(id){
		const cur = isExpanded(id);
		state.expanded[id] = !cur;
		render();
	}

	function isLocked(id){
		return !!state.locks[id];
	}

	function isDirectSelected(id){ return !!state.selected[id]; }
	function isEffectiveSelectedPack(id){
		if (state.previewPacksSet.size) return state.previewPacksSet.has(id);
		return isDirectSelected(id);
	}
function isPageIncluded(id){ return state.previewPagesSet.has(id); }

function recomputePreviewLocally(){
    const edges = Array.isArray(state.data?.edges) ? state.data.edges : [];
    const depAdj = Object.create(null);
    const containsPages = Object.create(null);
    for (const e of edges){
        if (e.rel === 'depends' && typeof e.from === 'string' && typeof e.to === 'string'){
            const from = e.from.replace(/^pack:/,''); const to = e.to.replace(/^pack:/,'');
            (depAdj[from] ||= []).push(to);
        }
        if (e.rel === 'contains' && typeof e.from === 'string' && typeof e.to === 'string'){
            const from = e.from.replace(/^pack:/,''); const to = e.to.replace(/^page:/,'');
            (containsPages[from] ||= []).push(to);
        }
    }
    const selected = Object.keys(state.selected);
    const seen = Object.create(null);
    function dfs(p){ if (seen[p]) return; seen[p]=true; const arr=depAdj[p]||[]; for (const q of arr) dfs(q); }
    for (const s of selected){ dfs(s); }
    const packs = new Set(Object.keys(seen));
    const pages = new Set();
    for (const p of packs){ const arr = containsPages[p]||[]; for (const t of arr) pages.add(t); }
    state.previewPacksSet = packs;
    state.previewPagesSet = pages;
}

const fetchDebounced = (()=>{ let t=null,last=null; return opts=>{ last=opts||null; if(t)clearTimeout(t); t=setTimeout(()=>{t=null;fetchData(last);},50); }; })();

function onTogglePack(id){
    if (isDirectSelected(id)){
			if (isLocked(id)){
				mw.notify('Cannot deselect: ' + state.locks[id], { type: 'warn' });
				return;
			}
			delete state.selected[id];
		} else {
			state.selected[id] = true;
		}
    recomputePreviewLocally();
    // Fetch immediately so API receives selected[] and returns preview/preflight/plan
    fetchData();
	}

	function treeNode(node){
		const hasChildren = Array.isArray(node.children) && node.children.length > 0;
		const expanded = isExpanded(node.id); // default expanded
		const li = document.createElement('li');
		li.className = 'lpm-node';
		const head = document.createElement('div');
		head.className = 'lpm-row'; head.setAttribute('role','row');
		if (node.type === 'pack'){
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'lpm-expander';
			btn.textContent = hasChildren ? (expanded ? '▾' : '▸') : '·';
			btn.disabled = !hasChildren;
			btn.setAttribute('aria-expanded', String(expanded));
			btn.addEventListener('click', () => toggleExpand(node.id));
			head.appendChild(btn);

			const cb = document.createElement('input');
			cb.type = 'checkbox'; cb.checked = isEffectiveSelectedPack(node.id);
			cb.disabled = isLocked(node.id);
			if (cb.disabled) cb.setAttribute('aria-disabled','true');
			const checkboxId = 'lpm-cb-' + state.uidPrefix + '-' + (++state.uids);
			cb.id = checkboxId;
			cb.name = 'pack[]';
			cb.addEventListener('change', () => onTogglePack(node.id));
			head.appendChild(cb);

            const nameWrap = document.createElement('div'); nameWrap.className = 'lpm-name';
			const labelEl = document.createElement('label');
			labelEl.htmlFor = checkboxId;
			labelEl.textContent = node.id;
			nameWrap.appendChild(labelEl);
            const meta = document.createElement('span'); meta.className = 'lpm-meta'; meta.textContent = countsText(node.id);
			nameWrap.appendChild(meta);
            // badges for installed/update
            const info = packNode(node.id) || {};
            const badges = document.createElement('span'); badges.className = 'lpm-badges';
            if (info.installedVersion){
                const b = document.createElement('span'); b.className = 'lpm-badge inst'; b.textContent = 'Installed ' + info.installedVersion; badges.appendChild(b);
            } else if (info.installed){
                const b = document.createElement('span'); b.className = 'lpm-badge inst'; b.textContent = 'Installed'; badges.appendChild(b);
            }
            if (info.updateAvailable && info.version){
                const b2 = document.createElement('span'); b2.className = 'lpm-badge update'; b2.textContent = 'Update to ' + info.version; badges.appendChild(b2);
            }
            if (badges.childNodes.length) nameWrap.appendChild(badges);
			head.appendChild(nameWrap);
			const ver = document.createElement('span'); ver.className = 'lpm-ver'; ver.textContent = info.version || '';
			head.appendChild(ver);
			const desc = document.createElement('span'); desc.className = 'lpm-desc-cell'; desc.textContent = info.description || '';
			head.appendChild(desc);
		} else {
			const inc = document.createElement('span'); inc.className = 'lpm-inc' + (isPageIncluded(node.id) ? ' on' : ''); inc.title = isPageIncluded(node.id) ? 'Included' : 'Not included';
			head.appendChild(inc);
			const label = document.createElement('span'); label.className = 'lpm-page-label'; label.textContent = node.id;
			head.appendChild(label);
		}
		li.appendChild(head);
		if (hasChildren && expanded){
			const ul = document.createElement('ul');
			for (const c of node.children){ ul.appendChild(treeNode(c)); }
			li.appendChild(ul);
		}
		return li;
	}

	function countsText(packId){
		const n = state.data?.nodes?.['pack:' + packId];
		if (!n) return '';
		const p = n.pagesBeneath ?? 0; const k = n.packsBeneath ?? 0;
		return `${p} pages · ${k} packs`;
	}

	function packNode(packId){ return state.data?.nodes?.['pack:' + packId] || null; }

	function renderTree(root){
		if (!state.data) return;
		const tree = state.data.tree || [];
		root.innerHTML = '';
		// keep state.uids monotonic across renders to guarantee unique ids globally
		if (!tree.length){
			const empty = document.createElement('div');
			empty.textContent = mw.msg('labkipackmanager-ui-no-packs');
			root.appendChild(empty);
			return;
		}
		const ul = document.createElement('ul'); ul.className = 'lpm-tree';
		for (const n of tree){ ul.appendChild(treeNode(n)); }
		root.appendChild(ul);
	}

	function renderSummary(root){
		const box = document.createElement('div');
		box.className = 'lpm-summary';
		const sel = state.data?.preview?.packs || Object.keys(state.selected);
		const pages = state.data?.preview?.pages || [];
		let text = `${mw.msg('labkipackmanager-ui-selected-packs')} ${sel.length}  |  Pages: ${pages.length}`;
		if (state.data?.errorKey){ text += `  |  Error: ${state.data.errorKey}`; }
		box.textContent = text;
		root.appendChild(box);

		// Pre-flight summary
		if (state.data?.preflight){
			const pf = state.data.preflight;
			const pfBox = document.createElement('div'); pfBox.className = 'lpm-summary';
			pfBox.textContent = `Pre-flight: Create ${pf.create || 0} | Update (unchanged) ${pf.update_unchanged || 0} | Update (modified) ${pf.update_modified || 0} | Pack-pack ${pf.pack_pack_conflicts || 0} | External ${pf.external_collisions || 0}`;
			root.appendChild(pfBox);
			// Simple drill-down lists toggled inline
			const makeList = (label, list) => {
				if (!Array.isArray(list) || !list.length) return null;
				const wrap = document.createElement('div'); wrap.style.marginTop = '4px';
				const btn = document.createElement('button'); btn.type='button'; btn.className='cdx-button'; btn.textContent = label + ` (${list.length})`;
				const ul = document.createElement('ul'); ul.style.margin='4px 0 0 16px'; ul.style.display='none';
				for (const t of list){ const li=document.createElement('li'); li.textContent=t; ul.appendChild(li); }
				btn.addEventListener('click', ()=>{ ul.style.display = (ul.style.display==='none') ? 'block' : 'none'; });
				wrap.appendChild(btn); wrap.appendChild(ul); return wrap;
			};
			const lists = pf.lists || {};
			for (const [key,label] of [ ['create','Create'], ['update_unchanged','Update (unchanged)'], ['update_modified','Update (modified)'], ['pack_pack_conflicts','Pack-pack conflicts'], ['external_collisions','External collisions'] ]){
				const el = makeList(label, lists[key] || []);
				if (el) root.appendChild(el);
			}
			// Show intra-selection conflicts (multiple selected packs own same page)
			if (Array.isArray(pf.selection_conflicts) && pf.selection_conflicts.length){
				const wrap = document.createElement('div'); wrap.style.marginTop='4px';
				const btn = document.createElement('button'); btn.type='button'; btn.className='cdx-button'; btn.textContent = `Selection conflicts (${pf.selection_conflicts.length})`;
				const ul = document.createElement('ul'); ul.style.margin='4px 0 0 16px'; ul.style.display='none';
				for (const row of pf.selection_conflicts){ const li=document.createElement('li'); li.textContent = `${row.page} ← owners: ${row.owners.join(', ')}`; ul.appendChild(li); }
				btn.addEventListener('click', ()=>{ ul.style.display = (ul.style.display==='none') ? 'block' : 'none'; });
				wrap.appendChild(btn); wrap.appendChild(ul); root.appendChild(wrap);
			}
			const hasCollisions = (lists.pack_pack_conflicts && lists.pack_pack_conflicts.length) || (lists.external_collisions && lists.external_collisions.length);
			if (hasCollisions){ root.appendChild(renderResolverPanel(lists)); }
		}

    // Details table for selected packs with version, installed/update, and description
	const table = document.createElement('table'); table.className = 'lpm-table';
	const thead = document.createElement('thead'); const trh = document.createElement('tr');
    for (const h of ['Pack', 'Version', 'Installed/Update', 'Description']){ const th=document.createElement('th'); th.textContent=h; trh.appendChild(th); }
	thead.appendChild(trh); table.appendChild(thead);
	const tbody = document.createElement('tbody');
	const packs = state.previewPacksSet.size ? Array.from(state.previewPacksSet) : Object.keys(state.selected);
	packs.sort();
	for (const id of packs){
		const n = packNode(id); if (!n) continue;
		const tr = document.createElement('tr');
		const td1 = document.createElement('td'); td1.textContent = id; tr.appendChild(td1);
		const td2 = document.createElement('td'); td2.textContent = n.version || ''; tr.appendChild(td2);
        const tdMid = document.createElement('td');
        const parts = [];
        if (n.installedVersion){ parts.push('Installed ' + n.installedVersion); }
        else if (n.installed){ parts.push('Installed'); }
        if (n.updateAvailable && n.version){ parts.push('Update to ' + n.version); }
        tdMid.textContent = parts.join(' · ');
        tr.appendChild(tdMid);
        const td3 = document.createElement('td'); td3.textContent = n.description || ''; tr.appendChild(td3);
		tbody.appendChild(tr);
	}
	table.appendChild(tbody);
	root.appendChild(table);

		// Plan preview and downloads
		if (state.lastPlan){
			const planBox = document.createElement('div'); planBox.className = 'lpm-summary';
			const s = state.lastPlan.summary || {};
			planBox.textContent = `Plan: Create ${s.create||0} | Update ${s.update||0} | Rename ${s.rename||0} | Skip ${s.skip||0} | Backup ${s.backup||0}`;
			const btnJson = document.createElement('button'); btnJson.type='button'; btnJson.className='cdx-button'; btnJson.style.marginLeft='6px'; btnJson.textContent = 'Download JSON';
			btnJson.addEventListener('click', ()=>downloadText('labki-plan.json', JSON.stringify(state.lastPlan, null, 2)) );
			const btnCsv = document.createElement('button'); btnCsv.type='button'; btnCsv.className='cdx-button'; btnCsv.style.marginLeft='4px'; btnCsv.textContent = 'Download CSV';
			btnCsv.addEventListener('click', ()=>downloadText('labki-plan.csv', planToCsv(state.lastPlan)) );
			planBox.appendChild(btnJson); planBox.appendChild(btnCsv);
			root.appendChild(planBox);
		}
	}

	function renderResolverPanel(lists){
		const wrap = document.createElement('div'); wrap.style.marginTop='8px';
		const h = document.createElement('div'); h.textContent = 'Resolve collisions'; h.style.fontWeight='600'; wrap.appendChild(h);
		const prefixWrap = document.createElement('div');
		const lbl = document.createElement('label'); lbl.textContent = 'Global prefix for renames:'; lbl.style.marginRight='6px';
		const inp = document.createElement('input'); inp.type='text'; inp.value = state.planDraft.globalPrefix || ''; inp.style.minWidth='160px';
		inp.addEventListener('input', ()=>{ state.planDraft.globalPrefix = inp.value; try{ localStorage.setItem('lpm_global_prefix', inp.value||''); }catch(e){} });
		prefixWrap.appendChild(lbl); prefixWrap.appendChild(inp); wrap.appendChild(prefixWrap);

		const makeRow = (title, type) => {
			const tr = document.createElement('tr');
			const td1 = document.createElement('td'); td1.textContent = title; tr.appendChild(td1);
			const td2 = document.createElement('td');
			const sel = document.createElement('select');
			const opts = type==='external' ? [ ['skip','Skip (default)'], ['backup','Backup & overwrite'], ['rename','Rename…'] ] : [ ['skip','Skip (default)'], ['rename','Rename…'] ];
			for (const [val,label] of opts){ const o=document.createElement('option'); o.value=val; o.textContent=label; sel.appendChild(o); }
			const pageDraft = state.planDraft.pages[title] || {};
			sel.value = pageDraft.action || 'skip';
			const rn = document.createElement('input'); rn.type='text'; rn.placeholder='New title'; rn.style.marginLeft='6px'; rn.value = pageDraft.renameTo || '';
			rn.style.display = (sel.value==='rename') ? '' : 'none';
			const bk = document.createElement('input'); bk.type='checkbox'; bk.style.marginLeft='6px'; bk.title='Backup existing page before overwrite'; bk.checked = !!pageDraft.backup;
			bk.style.display = (type==='external' && sel.value!=='skip') ? '' : 'none';
			sel.addEventListener('change', ()=>{ rn.style.display = (sel.value==='rename') ? '' : 'none'; bk.style.display = (type==='external' && sel.value!=='skip') ? '' : 'none'; state.planDraft.pages[title] = Object.assign({}, state.planDraft.pages[title]||{}, { action: sel.value }); });
			rn.addEventListener('input', ()=>{ state.planDraft.pages[title] = Object.assign({}, state.planDraft.pages[title]||{}, { renameTo: rn.value, action: 'rename' }); sel.value='rename'; rn.style.display=''; });
			bk.addEventListener('change', ()=>{ state.planDraft.pages[title] = Object.assign({}, state.planDraft.pages[title]||{}, { backup: bk.checked }); });
			td2.appendChild(sel); td2.appendChild(rn); td2.appendChild(bk); tr.appendChild(td2);
			return tr;
		};

		const table = document.createElement('table'); table.className='lpm-table';
		const thead = document.createElement('thead'); const trh = document.createElement('tr');
		for (const h of ['Page','Action']){ const th=document.createElement('th'); th.textContent=h; trh.appendChild(th); }
		thead.appendChild(trh); table.appendChild(thead);
		const tbody = document.createElement('tbody');
		const ext = lists.external_collisions || []; const ppc = lists.pack_pack_conflicts || [];
		for (const t of ext){ tbody.appendChild(makeRow(t, 'external')); }
		for (const t of ppc){ tbody.appendChild(makeRow(t, 'packpack')); }
		table.appendChild(tbody); wrap.appendChild(table);

		const btnApply = document.createElement('button'); btnApply.type='button'; btnApply.className='cdx-button cdx-button--action-progressive'; btnApply.textContent='Apply plan'; btnApply.style.marginTop='6px';
		btnApply.addEventListener('click', ()=>{ applyPlan(); });
		wrap.appendChild(btnApply);
		return wrap;
	}

	function applyPlan(){
		const plan = { globalPrefix: (state.planDraft.globalPrefix||'') || undefined, pages: {} };
		for (const [title, cfg] of Object.entries(state.planDraft.pages||{})){
			const rec = {};
			if (cfg.action) rec.action = cfg.action;
			if (cfg.renameTo) rec.renameTo = cfg.renameTo;
			if (cfg.backup) rec.backup = true;
			plan.pages[title] = rec;
		}
		fetchData({ plan });
	}

	function planToCsv(plan){
		const rows = [['title','finalTitle','action','backup']];
		const pages = Array.isArray(plan?.pages) ? plan.pages : (plan?.pages || []);
		if (Array.isArray(pages)){
			for (const p of pages){ rows.push([p.title, p.finalTitle, p.action, String(!!p.backup)]); }
		}else{
			for (const p of (plan.list||[])) rows.push([p.title,p.finalTitle,p.action,String(!!p.backup)]);
		}
		return rows.map(r => r.map(v => '"'+String(v).replace(/"/g,'""')+'"').join(',')).join('\n');
	}

	function downloadText(filename, text){
		const blob = new Blob([text], { type: 'text/plain' });
		const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = filename; a.click(); setTimeout(()=>URL.revokeObjectURL(a.href), 1000);
	}

	function renderRepoPicker(container){
		const wrap = document.createElement('div');
		wrap.style.marginBottom = '8px';
		const sources = (state.data && Array.isArray(state.data.sources) ? state.data.sources : state.lastSources) || [];
		if (!sources.length) return;
		const label = document.createElement('label'); label.textContent = mw.msg('labkipackmanager-ui-content-repo'); label.style.marginRight = '6px';
		const selectId = 'lpm-repo-select';
		label.htmlFor = selectId;
		const select = document.createElement('select');
		select.id = selectId; select.name = 'repo';
		for (const name of sources){
			const opt = document.createElement('option'); opt.value = name; opt.textContent = name; if (name === state.repo) opt.selected = true; select.appendChild(opt);
		}
		select.addEventListener('change', () => { state.repo = select.value; state.selected = Object.create(null); state.expanded = Object.create(null); fetchData(); });
		wrap.appendChild(label); wrap.appendChild(select);
		const active = document.createElement('span'); active.style.marginLeft = '8px'; active.style.color = '#555'; active.textContent = `${mw.msg('labkipackmanager-ui-current')} ${state.repo || ''}`; wrap.appendChild(active);

		const btnLoad = document.createElement('button'); btnLoad.type = 'button'; btnLoad.className = 'cdx-button'; btnLoad.style.marginLeft = '8px'; btnLoad.textContent = mw.msg('labkipackmanager-ui-load');
		btnLoad.addEventListener('click', () => { fetchData(); });
		wrap.appendChild(btnLoad);

		const btnRefresh = document.createElement('button'); btnRefresh.type = 'button'; btnRefresh.className = 'cdx-button cdx-button--action-progressive'; btnRefresh.style.marginLeft = '4px'; btnRefresh.textContent = mw.msg('labkipackmanager-ui-refresh');
		btnRefresh.addEventListener('click', () => { fetchData({ refresh: true }); });
		wrap.appendChild(btnRefresh);

		const status = state.data?.status || {};
		const meta = document.createElement('span'); meta.style.marginLeft = '8px'; meta.style.color = '#666';
		if (typeof status.fetchedAt === 'number'){
			const dt = new Date(status.fetchedAt * 1000);
			meta.textContent = `${mw.msg('labkipackmanager-ui-fetched')} ${dt.toISOString().slice(0,16)} (UTC) ${status.usingCache ? mw.msg('labkipackmanager-ui-cache-suffix') : ''}`;
		}
		wrap.appendChild(meta);
		container.appendChild(wrap);
	}

	function render(){
		const el = document.getElementById('labki-pack-manager-root');
		if (!el){ return; }
		el.innerHTML = '';
		const top = document.createElement('div'); el.appendChild(top);
		renderRepoPicker(top);
		const layout = document.createElement('div'); layout.className = 'lpm-layout'; el.appendChild(layout);
		const left = document.createElement('div'); left.className = 'lpm-left';
		const right = document.createElement('div'); right.className = 'lpm-right';
		layout.appendChild(left); layout.appendChild(right);
		renderTree(left);
		renderSummary(right);
		renderGraph(right);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => fetchData() );
	} else {
		fetchData();
	}

// Mermaid graph rendering (Strategy A: re-render code with class blocks)
function renderGraph(container){
	const graphWrap = document.createElement('div'); graphWrap.style.marginTop = '8px';
	const graphEl = document.createElement('div'); graphEl.id = 'lpm-graph'; graphEl.className = 'lpm-graph'; graphEl.style.maxWidth = '480px';
	graphWrap.appendChild(graphEl); container.appendChild(graphWrap);
    const legend = document.createElement('div'); legend.className = 'lpm-legend';
    legend.innerHTML = '<span class="lpm-swatch sel"></span>Selected <span class="lpm-swatch imp"></span>Implied <span class="lpm-swatch pg"></span>Pages';
	container.appendChild(legend);
    if (window.mermaid && window.mermaid.run){ state.mermaidReady = true; updateGraph(); return; }
    // If ext.mermaid is loaded, a global mermaid is usually available. If not, fallback to CDN in dev.
    const s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js';
    s.onload = () => { if (window.mermaid) { try{ window.mermaid.initialize({ startOnLoad:false }); }catch(e){} state.mermaidReady = true; updateGraph(); } };
    document.body.appendChild(s);
}

function updateGraph(){
	if (!state.mermaidReady || !state.data) return;
	const base = state.data.mermaid?.code || '';
	const idMap = state.data.mermaid?.idMap || {};
	if (!base) return;
	const sel = state.previewPacksSet.size ? Array.from(state.previewPacksSet) : Object.keys(state.selected);
	const direct = Object.keys(state.selected);
	const toIds = (arr) => arr.map(k => idMap['pack:' + k]).filter(Boolean);
    const clsLines = [
        'classDef selected stroke:#2563eb,stroke-width:2px;',
        'classDef implied stroke:#10b981,stroke-width:2px;',
        'classDef pageIncluded stroke:#22c55e,stroke-width:2px,fill:#ecfdf5;'
    ];
	const selectedIds = toIds(direct);
	const impliedIds = toIds(sel.filter(k => direct.indexOf(k) === -1));
	if (selectedIds.length) clsLines.push('class ' + selectedIds.join(',') + ' selected;');
	if (impliedIds.length) clsLines.push('class ' + impliedIds.join(',') + ' implied;');
	// Pages: color them green when included (match tree dot)
    const pageIncluded = Array.isArray(state.data?.preview?.pages) ? state.data.preview.pages : [];
    const pageIds = pageIncluded.map(p => idMap['page:' + p]).filter(Boolean);
    if (pageIds.length) {
        clsLines.push('class ' + pageIds.join(',') + ' pageIncluded;');
    }
	const code = base + '\n' + clsLines.join('\n');
	// simple render using mermaidAPI through global mermaid
	try {
		// mermaid 10 ESM default init in module; re-render by setting innerHTML
		const target = document.getElementById('lpm-graph');
		target.innerHTML = '<pre class="mermaid">' + code.replace(/</g,'&lt;') + '</pre>';
		if (window.mermaid && window.mermaid.run) { window.mermaid.run({ querySelector: '#lpm-graph .mermaid' }); }
		attachHoverSync(target, idMap);
	} catch (e) {}
}

// Hover sync: tree -> graph and graph -> tree
function attachHoverSync(target, idMap){
	const mapPackToSvgId = {};
	for (const key in idMap){ if (key.startsWith('pack:')) mapPackToSvgId[key.slice(5)] = idMap[key]; }
	const svg = target.querySelector('svg'); if (!svg) return;
	// graph -> tree
	for (const [packId, svgId] of Object.entries(mapPackToSvgId)){
		const node = svg.querySelector('#' + svgId);
		if (!node) continue;
		node.addEventListener('mouseenter', () => highlightTree(packId, true));
		node.addEventListener('mouseleave', () => highlightTree(packId, false));
	}
	// tree -> graph
	document.querySelectorAll('.lpm-row').forEach(row => {
		const label = row.querySelector('label');
		if (!label) return;
		const packId = label.textContent;
		if (!mapPackToSvgId[packId]) return;
		row.addEventListener('mouseenter', () => highlightGraph(mapPackToSvgId[packId], true));
		row.addEventListener('mouseleave', () => highlightGraph(mapPackToSvgId[packId], false));
	});
}

function highlightTree(packId, on){
	document.querySelectorAll('.lpm-row').forEach(row => {
		const label = row.querySelector('label');
		if (label && label.textContent === packId){ row.classList.toggle('lpm-hover', on); }
	});
}

function highlightGraph(svgId, on){
	const node = document.querySelector('#lpm-graph svg #' + svgId);
	if (!node) return;
	if (on){ node.classList.add('hovered'); } else { node.classList.remove('hovered'); }
}
})();


