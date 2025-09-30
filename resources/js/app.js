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
		uidPrefix: (Math.random().toString(36).slice(2))
	};

	function isExpanded(id){
		return state.expanded[id] !== undefined ? state.expanded[id] : true;
	}

	function apiUrl(selected, opts){
		let base = mw.util.wikiScript('api') + '?action=labkipacks&format=json&formatversion=2';
		if (state.repo) base += '&repo=' + encodeURIComponent(state.repo);
		if (opts && opts.refresh) base += '&refresh=1';
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
	function isPageIncluded(id){
		return state.previewPagesSet.has(id);
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

const fetchDebounced = (function(){
    let t = null, lastOpts = null;
    return function(opts){
        lastOpts = opts || null;
        if (t){ clearTimeout(t); }
        t = setTimeout(() => { t = null; fetchData(lastOpts); }, 50);
    };
})();

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
    fetchDebounced();
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
			head.appendChild(nameWrap);
			const info = packNode(node.id) || {};
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

	// Details table for selected packs with version and description
	const table = document.createElement('table'); table.className = 'lpm-table';
	const thead = document.createElement('thead'); const trh = document.createElement('tr');
	for (const h of ['Pack', 'Version', 'Description']){ const th=document.createElement('th'); th.textContent=h; trh.appendChild(th); }
	thead.appendChild(trh); table.appendChild(thead);
	const tbody = document.createElement('tbody');
	const packs = state.previewPacksSet.size ? Array.from(state.previewPacksSet) : Object.keys(state.selected);
	packs.sort();
	for (const id of packs){
		const n = packNode(id); if (!n) continue;
		const tr = document.createElement('tr');
		const td1 = document.createElement('td'); td1.textContent = id; tr.appendChild(td1);
		const td2 = document.createElement('td'); td2.textContent = n.version || ''; tr.appendChild(td2);
		const td3 = document.createElement('td'); td3.textContent = n.description || ''; tr.appendChild(td3);
		tbody.appendChild(tr);
	}
	table.appendChild(tbody);
	root.appendChild(table);
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
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => fetchData() );
	} else {
		fetchData();
	}
})();


