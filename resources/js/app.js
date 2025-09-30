// Minimal placeholder module for Phase 0
function initLabkiPackManager() {
	const el = document.getElementById('labki-pack-manager-root');
	if (el) {
		el.textContent = 'LabkiPackManager UI will load here.';
	}
}

if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', initLabkiPackManager );
} else {
    initLabkiPackManager();
}


