// Minimal Phase 3 UI: Tree + SelectionModel + Summary
(function(){
	const state = {
		data: null,
		expanded: Object.create(null),
		selected: Object.create(null),
		locks: Object.create(null),
		repo: null,
		lastSources: []
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

	function isSelected(id){
		return !!state.selected[id];
	}

	function onTogglePack(id){
		if (isSelected(id)){
			if (isLocked(id)){
				mw.notify('Cannot deselect: ' + state.locks[id], { type: 'warn' });
				return;
			}
			delete state.selected[id];
		} else {
			state.selected[id] = true;
		}
		fetchData();
	}

	function treeNode(node){
		const hasChildren = Array.isArray(node.children) && node.children.length > 0;
		const expanded = isExpanded(node.id); // default expanded
		const li = document.createElement('li');
		li.className = 'lpm-node';
		const head = document.createElement('div');
		head.className = 'lpm-row';
		if (node.type === 'pack'){
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'lpm-expander';
			btn.textContent = hasChildren ? (expanded ? '▾' : '▸') : '·';
			btn.disabled = !hasChildren;
			btn.addEventListener('click', () => toggleExpand(node.id));
			head.appendChild(btn);

			const cb = document.createElement('input');
			cb.type = 'checkbox'; cb.checked = isSelected(node.id);
			cb.disabled = isLocked(node.id);
			const checkboxId = 'lpm-cb-' + node.id.replace(/[^a-zA-Z0-9_-]/g, '-');
			cb.id = checkboxId;
			cb.name = 'pack[]';
			cb.addEventListener('change', () => onTogglePack(node.id));
			head.appendChild(cb);

			const labelEl = document.createElement('label');
			labelEl.htmlFor = checkboxId;
			labelEl.textContent = node.id;
			head.appendChild(labelEl);
			const meta = document.createElement('span'); meta.className = 'lpm-meta'; meta.textContent = countsText(node.id);
			head.appendChild(meta);
		} else {
			const spacer = document.createElement('span'); spacer.textContent = '  ';
			head.appendChild(spacer);
			const label = document.createElement('span');
			label.textContent = node.id;
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

	function renderTree(root){
		if (!state.data) return;
		const tree = state.data.tree || [];
		root.innerHTML = '';
		if (!tree.length){
			const empty = document.createElement('div');
			empty.textContent = 'No packs available for the selected source. Click Load or Refresh.';
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
		let text = `Selected packs: ${sel.length}  |  Pages: ${pages.length}`;
		if (state.data?.errorKey){ text += `  |  Error: ${state.data.errorKey}`; }
		box.textContent = text;
		root.appendChild(box);
	}

	function renderRepoPicker(container){
		const wrap = document.createElement('div');
		wrap.style.marginBottom = '8px';
		const sources = (state.data && Array.isArray(state.data.sources) ? state.data.sources : state.lastSources) || [];
		if (!sources.length) return;
		const label = document.createElement('label'); label.textContent = 'Content repo:'; label.style.marginRight = '6px';
		const selectId = 'lpm-repo-select';
		label.htmlFor = selectId;
		const select = document.createElement('select');
		select.id = selectId; select.name = 'repo';
		for (const name of sources){
			const opt = document.createElement('option'); opt.value = name; opt.textContent = name; if (name === state.repo) opt.selected = true; select.appendChild(opt);
		}
		select.addEventListener('change', () => { state.repo = select.value; state.selected = Object.create(null); state.expanded = Object.create(null); fetchData(); });
		wrap.appendChild(label); wrap.appendChild(select);
		const active = document.createElement('span'); active.style.marginLeft = '8px'; active.style.color = '#555'; active.textContent = `Current: ${state.repo || ''}`; wrap.appendChild(active);

		const btnLoad = document.createElement('button'); btnLoad.type = 'button'; btnLoad.className = 'cdx-button'; btnLoad.style.marginLeft = '8px'; btnLoad.textContent = 'Load';
		btnLoad.addEventListener('click', () => { fetchData(); });
		wrap.appendChild(btnLoad);

		const btnRefresh = document.createElement('button'); btnRefresh.type = 'button'; btnRefresh.className = 'cdx-button cdx-button--action-progressive'; btnRefresh.style.marginLeft = '4px'; btnRefresh.textContent = 'Refresh';
		btnRefresh.addEventListener('click', () => { fetchData({ refresh: true }); });
		wrap.appendChild(btnRefresh);

		const status = state.data?.status || {};
		const meta = document.createElement('span'); meta.style.marginLeft = '8px'; meta.style.color = '#666';
		if (typeof status.fetchedAt === 'number'){
			const dt = new Date(status.fetchedAt * 1000);
			meta.textContent = `Fetched ${dt.toISOString().slice(0,16)} (UTC) ${status.usingCache ? '· cache' : ''}`;
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


