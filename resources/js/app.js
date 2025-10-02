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

	// Shared constants/utilities
	const LOCAL_STORAGE_GLOBAL_PREFIX_KEY = 'lpm_global_prefix';
	const KNOWN_NS = new Set([
		'Template','Form','Module','Category','Property','Help','User','File','Image','Project','MediaWiki','Media','Special','Talk','User talk','Project talk','File talk','MediaWiki talk','Template talk','Help talk','Category talk','Module talk'
	]);
	function isKnownNamespace(ns){ return KNOWN_NS.has(ns); }

	// Restore last used global prefix from localStorage
	try {
		const gp = localStorage.getItem(LOCAL_STORAGE_GLOBAL_PREFIX_KEY);
		if (typeof gp === 'string' && gp) { state.planDraft.globalPrefix = gp; }
	} catch(e) {}

	function isExpanded(id){
		return state.expanded[id] !== undefined ? state.expanded[id] : true;
	}

	// Compute final title given current per-page rename and global prefix, preserving namespaces
	function finalTitleFor(title){
		const p = (state.planDraft.pages||{})[title]||{};
		const gp = state.planDraft.globalPrefix||'';
		// If explicit rename provided, combine with global prefix while preserving namespace of original
		if (p.action === 'rename' && typeof p.renameTo === 'string' && p.renameTo){
			const idxR = title.indexOf(':');
			if (idxR > 0){
				const ns = title.slice(0, idxR);
				if ( isKnownNamespace(ns) ){
					if (gp){ if (p.renameTo.startsWith(gp + '/')) return ns + ':' + p.renameTo; return ns + ':' + gp + '/' + p.renameTo; }
					return ns + ':' + p.renameTo;
				}
			}
			// No recognized namespace (treat as main)
			if (gp){ if (p.renameTo.startsWith(gp + ':')) return p.renameTo; return gp + ':' + p.renameTo; }
			return p.renameTo;
		}
		// Apply global prefix to original title
		if (!gp){ return title; }
		const idx = title.indexOf(':');
		if (idx > 0){
			const ns = title.slice(0, idx); const rest = title.slice(idx+1);
			if ( isKnownNamespace(ns) ){
				if (rest.startsWith(gp + '/')) return title;
				return ns + ':' + gp + '/' + rest;
			}
			if (title.startsWith(gp + ':')) return title;
			return gp + ':' + title;
		}
		if (title.startsWith(gp + ':')) return title;
		return gp + ':' + title;
	}

	// Utilities for accessible form controls
	function safeIdFromTitle(prefix, title){
		return prefix + '-' + state.uidPrefix + '-' + String(title).toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
	}
	function makeSrLabel(text){
		const lab = document.createElement('label');
		lab.textContent = text;
		lab.style.position = 'absolute'; lab.style.left = '-10000px'; lab.style.width='1px'; lab.style.height='1px'; lab.style.overflow='hidden';
		return lab;
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
function isPageSkipped(id){ const p = (state.planDraft.pages||{})[id]; return !!(p && p.action === 'skip'); }
function isPageCollidingNow(title){
    const lists = (state.data && state.data.preflight && state.data.preflight.lists) || {};
    const ext = new Set(lists.external_collisions || []);
    const ppc = new Set(lists.pack_pack_conflicts || []);
    if (isPageSkipped(title)) return false;
    const initiallyColliding = ext.has(title) || ppc.has(title);
    if (!initiallyColliding) return false;
    // If mapping changes the final title, collision is considered resolved
    return finalTitleFor(title) === title;
}

// Update existing tree page badges (dot colors) without re-rendering the whole tree
function refreshTreeBadges(){
    document.querySelectorAll('.lpm-row').forEach(row => {
        const pageLabel = row.querySelector('.lpm-page-label');
        const inc = row.querySelector('.lpm-inc');
        if (!pageLabel || !inc) return;
        const title = pageLabel.textContent;
        const skipped = isPageSkipped(title);
        const colliding = isPageCollidingNow(title);
        if (skipped){
            inc.className = 'lpm-inc'; inc.style.background='#facc15'; inc.style.border='1px solid #eab308'; inc.title = 'Skipped';
        } else if (colliding){
            inc.className = 'lpm-inc'; inc.style.background='#fecaca'; inc.style.border='1px solid #ef4444'; inc.title = 'Collision';
        } else {
            inc.className = 'lpm-inc' + (isPageIncluded(title) ? ' on' : ''); inc.style.background=''; inc.style.border=''; inc.title = isPageIncluded(title) ? 'Included' : 'Not included';
        }
    });
}

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
            const skipped = isPageSkipped(node.id);
            const colliding = isPageCollidingNow(node.id);
            const inc = document.createElement('span');
            if (skipped){
                inc.className = 'lpm-inc'; inc.style.background='#facc15'; inc.style.border='1px solid #eab308'; inc.title = 'Skipped';
            } else if (colliding){
                inc.className = 'lpm-inc'; inc.style.background='#fecaca'; inc.style.border='1px solid #ef4444'; inc.title = 'Collision';
            } else {
                inc.className = 'lpm-inc' + (isPageIncluded(node.id) ? ' on' : ''); inc.title = isPageIncluded(node.id) ? 'Included' : 'Not included';
            }
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
			// Simple drill-down lists toggled inline
			const makeList = (label, list) => {
				if (!Array.isArray(list) || !list.length) return null;
				const wrap = document.createElement('div'); wrap.style.marginTop = '4px';
				const btn = document.createElement('button'); btn.type='button'; btn.className='cdx-button'; btn.textContent = label + ` (${list.length})`;
				const ul = document.createElement('ul'); ul.style.margin='4px 0 0 16px'; ul.style.display='none';
				for (const t of list){ const li=document.createElement('li'); const f=finalTitleFor(t); li.textContent = (f && f!==t) ? (t + ' \u2192 ' + f) : t; ul.appendChild(li); }
				btn.addEventListener('click', ()=>{ ul.style.display = (ul.style.display==='none') ? 'block' : 'none'; });
				wrap.appendChild(btn); wrap.appendChild(ul); return wrap;
			};
            const lists = pf.lists || {};
            // Recalculate visible collision lists based on current final names: if final name differs, treat as resolved
            const isResolvedByMapping = (t) => finalTitleFor(t) !== t;
			const resolvedFromCollisions = []
				.concat((lists.pack_pack_conflicts||[]).filter(t => isResolvedByMapping(t)))
				.concat((lists.external_collisions||[]).filter(t => isResolvedByMapping(t)));
			const filteredLists = {
				create: (lists.create || []).concat(resolvedFromCollisions),
                update_unchanged: lists.update_unchanged || [],
                update_modified: lists.update_modified || [],
                pack_pack_conflicts: (lists.pack_pack_conflicts || []).filter(t => !isResolvedByMapping(t)),
                external_collisions: (lists.external_collisions || []).filter(t => !isResolvedByMapping(t))
            };
			// Dynamic counts using filtered lists
			pfBox.textContent = `Pre-flight: Create ${filteredLists.create.length} | Update (unchanged) ${filteredLists.update_unchanged.length} | Update (modified) ${filteredLists.update_modified.length} | Pack-pack ${filteredLists.pack_pack_conflicts.length} | External ${filteredLists.external_collisions.length}`;
			root.appendChild(pfBox);
            for (const [key,label] of [ ['create','Create'], ['update_unchanged','Update (unchanged)'], ['update_modified','Update (modified)'], ['pack_pack_conflicts','Pack-pack conflicts'], ['external_collisions','External collisions'] ]){
                const el = makeList(label, filteredLists[key] || []);
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
            const hasCollisions = (filteredLists.pack_pack_conflicts && filteredLists.pack_pack_conflicts.length) || (filteredLists.external_collisions && filteredLists.external_collisions.length);
			// Always show resolver so users can set global prefix even without collisions
			root.appendChild(renderResolverPanel(filteredLists, pf));
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

		// Plan preview and downloads: ensure we prompt server for a plan at least once
		if (!state.lastPlan && state.previewPagesSet.size){
			fetchDebounced({ plan: buildPlanFromDraft() });
		}
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

function renderResolverPanel(lists, pf){
		const wrap = document.createElement('div'); wrap.style.marginTop='8px';
		const h = document.createElement('div'); h.textContent = 'Resolve collisions'; h.style.fontWeight='600'; wrap.appendChild(h);
		const prefixWrap = document.createElement('div');
		const lbl = document.createElement('label'); lbl.textContent = 'Global prefix for renames:'; lbl.style.marginRight='6px';
		const inp = document.createElement('input'); inp.type='text'; inp.value = state.planDraft.globalPrefix || ''; inp.style.minWidth='160px';
		const gpId = safeIdFromTitle('lpm-gp', 'global'); inp.id = gpId; lbl.htmlFor = gpId; inp.name = 'lpm-global-prefix'; inp.autocomplete = 'off';
        // Update preview, persist prefix, and refresh type colors when global prefix changes
        inp.addEventListener('input', ()=>{
            state.planDraft.globalPrefix = inp.value;
            try { localStorage.setItem(LOCAL_STORAGE_GLOBAL_PREFIX_KEY, inp.value || ''); } catch(e) {}
            renderPreviewTable();
            refreshTreeBadges();
            updateGraph();
        });
		prefixWrap.appendChild(lbl); prefixWrap.appendChild(inp); wrap.appendChild(prefixWrap);

		// Live preview of resulting titles after applying per-page rename or global prefix
		const prev = document.createElement('div'); prev.className = 'lpm-summary'; prev.style.marginTop='6px';
		function effectiveFinal(title){
			const p = (state.planDraft.pages||{})[title]||{};
			const gp = state.planDraft.globalPrefix||'';
			// When a rename is requested, combine rename leaf with global prefix while preserving namespace
			if (p.action === 'rename' && typeof p.renameTo === 'string' && p.renameTo){
				const idx = title.indexOf(':');
				if (idx > 0){
					const ns = title.slice(0, idx);
					const knownNs = new Set(['Template','Form','Module','Category','Property','Help','User','File','Image','Project','MediaWiki','Media','Special','Talk','User talk','Project talk','File talk','MediaWiki talk','Template talk','Help talk','Category talk','Module talk']);
					if (knownNs.has(ns)){
						if (gp){
							if (p.renameTo.startsWith(gp + '/')) return ns + ':' + p.renameTo;
							return ns + ':' + gp + '/' + p.renameTo;
						}
						return ns + ':' + p.renameTo;
					}
				}
				// No recognized namespace → treat as main-namespace style
				if (gp){ if (p.renameTo.startsWith(gp + ':')) return p.renameTo; return gp + ':' + p.renameTo; }
				return p.renameTo;
			}
			if (!gp){ return title; }
			// Avoid double-applying global prefix
			const idx = title.indexOf(':');
			if (idx > 0){
				const ns = title.slice(0, idx); const rest = title.slice(idx+1);
				// Known MW namespaces where we should keep namespace and use subpage prefixing
				const knownNs = new Set(['Template','Form','Module','Category','Property','Help','User','File','Image','Project','MediaWiki','Media','Special','Talk','User talk','Project talk','File talk','MediaWiki talk','Template talk','Help talk','Category talk','Module talk']);
				if (knownNs.has(ns)){
					if (rest.startsWith(gp + '/')) return title;
					return ns + ':' + gp + '/' + rest;
				}
				// Otherwise treat as plain title (e.g., 'Main:Foo' is part of title)
				if (title.startsWith(gp + ':')) return title;
				return gp + ':' + title;
			}
			// No colon → main namespace
			if (title.startsWith(gp + ':')) return title;
			return gp + ':' + title;
		}
		function renderPreviewTable(){
			prev.innerHTML = '';
			const hdr = document.createElement('div'); hdr.textContent = 'Resulting titles preview'; hdr.style.fontWeight='600'; prev.appendChild(hdr);
			const table = document.createElement('table'); table.className='lpm-table';
			const thead = document.createElement('thead'); const trh = document.createElement('tr');
			for (const h of ['Original','Collides with (owner)','Final (after rename/prefix)']){ const th=document.createElement('th'); th.textContent=h; trh.appendChild(th); }
			thead.appendChild(trh); table.appendChild(thead);
			const tbody = document.createElement('tbody');
			const owners = (pf && pf.owners) || {};
			const allPages = Array.isArray(state.data?.preview?.pages) ? state.data.preview.pages.slice().sort() : [];
			for (const t of allPages){
				const tr = document.createElement('tr');
				const cWith = owners[t] ? (owners[t].pack_id + ' @ ' + (owners[t].source_repo||'')) : '';
				const td1 = document.createElement('td'); td1.textContent = t; tr.appendChild(td1);
				const td2 = document.createElement('td'); td2.textContent = cWith; tr.appendChild(td2);
				const td3 = document.createElement('td'); td3.textContent = effectiveFinal(t); tr.appendChild(td3);
				tbody.appendChild(tr);
			}
			table.appendChild(tbody); prev.appendChild(table);
		}
		renderPreviewTable();
		wrap.appendChild(prev);

		// (makeRow helper removed; inline row creation below is the single source of truth)

        const table = document.createElement('table'); table.className='lpm-table';
        const thead = document.createElement('thead'); const trh = document.createElement('tr');
        for (const h of ['Page','Type','Action','Rename to','Backup']){ const th=document.createElement('th'); th.textContent=h; trh.appendChild(th); }
        thead.appendChild(trh); table.appendChild(thead);
        const tbody = document.createElement('tbody');
        const extSet = new Set(lists.external_collisions || []); const ppcSet = new Set(lists.pack_pack_conflicts || []);
        const allPages = Array.isArray(state.data?.preview?.pages) ? state.data.preview.pages.slice().sort() : [];
        const computeType = (title) => {
            const p = (state.planDraft.pages||{})[title] || {};
            if (p.action === 'skip') return 'Skip';
            const initiallyPackPack = ppcSet.has(title);
            const initiallyExternal = extSet.has(title);
            const finalT = finalTitleFor(title);
            if ((initiallyPackPack || initiallyExternal) && finalT !== title) return 'Resolved';
            if (initiallyPackPack) return 'Pack-pack';
            if (initiallyExternal) return 'External';
            return 'OK';
        };
        const typeColor = (type) => {
            if (type === 'External' || type === 'Pack-pack') return '#b91c1c'; // red-700
            if (type === 'Resolved' || type === 'OK') return '#16a34a'; // green-600
            if (type === 'Skip') return '#ca8a04'; // yellow-600
            return '';
        };
        const rowRefs = [];
        for (const t of allPages){
            const type = computeType(t);
            const tr = document.createElement('tr');
            const tdTitle = document.createElement('td'); tdTitle.textContent = t; tr.appendChild(tdTitle);
            const tdType = document.createElement('td'); tdType.textContent = type; tdType.style.fontWeight='600'; tdType.style.color = typeColor(type); tr.appendChild(tdType);
            const tdAction = document.createElement('td');
            const selectAction = document.createElement('select');
            const options = (type === 'External' || type === 'Pack-pack') ? [ ['', '---'], ['skip','Skip'], ['update','Backup & Overwrite'], ['rename','Rename…'] ] : [ ['', '---'], ['skip','Skip'], ['rename','Rename…'] ];
            const pageDraft = state.planDraft.pages[t] || {};
            for (const [val,label] of options){ const o=document.createElement('option'); o.value=val; o.textContent=label; if ((pageDraft.action|| '')===val) o.selected=true; selectAction.appendChild(o); }
            selectAction.addEventListener('change', (ev)=>{ const v = ev.target.value; if (!v) { if (state.planDraft.pages[t]) delete state.planDraft.pages[t].action; } else { state.planDraft.pages[t] = Object.assign({}, state.planDraft.pages[t]||{}, { action: v }); } renderPreviewTable(); let ty=computeType(t); if (v==='skip') ty='Skip'; tdType.textContent=ty; tdType.style.color=typeColor(ty); refreshTreeBadges(); updateGraph(); fetchDebounced({ plan: buildPlanFromDraft() }); });
            tdAction.appendChild(selectAction); tr.appendChild(tdAction);
            const tdRename = document.createElement('td'); const renameInput = document.createElement('input'); renameInput.type='text'; renameInput.value = pageDraft.renameTo || ''; renameInput.disabled = (selectAction.value!=='rename');
            renameInput.addEventListener('input', (ev)=>{ state.planDraft.pages[t] = Object.assign({}, state.planDraft.pages[t]||{}, { renameTo: ev.target.value, action: 'rename' }); selectAction.value='rename'; renameInput.disabled=false; renderPreviewTable(); const ty=computeType(t); tdType.textContent = ty; tdType.style.color = typeColor(ty); refreshTreeBadges(); updateGraph(); /* no fetch here to preserve focus while typing */ });
            renameInput.addEventListener('blur', ()=>{ fetchDebounced({ plan: buildPlanFromDraft() }); });
            tdRename.appendChild(renameInput); tr.appendChild(tdRename);
            const tdBackup = document.createElement('td'); const backupCheckbox = document.createElement('input'); backupCheckbox.type='checkbox'; backupCheckbox.checked = !!pageDraft.backup; backupCheckbox.disabled = !(type==='External' || type==='Pack-pack') || (selectAction.value!=='update'); backupCheckbox.addEventListener('change', (ev)=>{ state.planDraft.pages[t] = Object.assign({}, state.planDraft.pages[t]||{}, { backup: ev.target.checked }); refreshTreeBadges(); updateGraph(); fetchDebounced({ plan: buildPlanFromDraft() }); }); tdBackup.appendChild(backupCheckbox); tr.appendChild(tdBackup);
            tbody.appendChild(tr);
            rowRefs.push({ title: t, tdType });
        }
        table.appendChild(tbody); wrap.appendChild(table);

        // Update types live when global prefix changes
        inp.addEventListener('input', ()=>{ for (const r of rowRefs){ const ty=computeType(r.title); r.tdType.textContent = ty; r.tdType.style.color = typeColor(ty); } });

        return wrap;
	}

    function buildPlanFromDraft(){
        const plan = { globalPrefix: (state.planDraft.globalPrefix||'') || undefined, pages: {} };
        for (const [title, cfg] of Object.entries(state.planDraft.pages||{})){
            const rec = {};
            if (cfg.action) rec.action = cfg.action;
            if (cfg.renameTo) rec.renameTo = cfg.renameTo;
            if (cfg.backup) rec.backup = true;
            plan.pages[title] = rec;
        }
        return plan;
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
        'classDef pageIncluded stroke:#22c55e,stroke-width:2px,fill:#ecfdf5;',
        'classDef pageSkipped stroke:#d97706,stroke-width:2px,fill:#fffbeb;',
        'classDef pageCollision stroke:#ef4444,stroke-width:2px,fill:#fee2e2;'
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
    // Skipped pages
    const skippedPages = Object.entries(state.planDraft.pages||{}).filter(([k,v]) => v && v.action === 'skip').map(([k])=>k);
    const skippedIds = skippedPages.map(p => idMap['page:' + p]).filter(Boolean);
    if (skippedIds.length){
        clsLines.push('class ' + skippedIds.join(',') + ' pageSkipped;');
    }
    // Colliding pages (unresolved)
    const lists = (state.data && state.data.preflight && state.data.preflight.lists) || {};
    const extSet = new Set(lists.external_collisions || []);
    const ppcSet = new Set(lists.pack_pack_conflicts || []);
    const collidingUnresolved = Array.isArray(state.data?.preview?.pages) ? state.data.preview.pages.filter(t => (extSet.has(t)||ppcSet.has(t)) && finalTitleFor(t)===t && !isPageSkipped(t)) : [];
    const collidingIds = collidingUnresolved.map(p => idMap['page:' + p]).filter(Boolean);
    if (collidingIds.length){ clsLines.push('class ' + collidingIds.join(',') + ' pageCollision;'); }
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


