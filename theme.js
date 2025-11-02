(function(){
	var toggle = document.getElementById('toggleReducedMotion');
	var prefers = false;
	try {
		prefers = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	} catch(e) { prefers = false; }
	var stored = 'auto';
	try { stored = localStorage.getItem('reducedMotion') || 'auto'; } catch(e) { stored = 'auto'; }
	var effective = (stored === 'on') || (stored === 'auto' && prefers);
	document.body.setAttribute('data-reduced-motion', effective ? '1' : '0');
	if (toggle) { toggle.checked = !!effective; }
	function apply(val){
		var on = !!val;
		document.body.setAttribute('data-reduced-motion', on ? '1' : '0');
		try { localStorage.setItem('reducedMotion', on ? 'on' : 'off'); } catch(e) { /* ignore */ }
	}
	if (toggle) {
		toggle.addEventListener('change', function(){ apply(this.checked); });
	}

    // Expressive parallax (throttled, disabled with reduced motion)
    var supportsFine = false;
    try { supportsFine = window.matchMedia && window.matchMedia('(pointer: fine)').matches; } catch(e) { supportsFine = false; }
    var ticking = false;
    var last = { x: 0.5, y: 0.3 };
    function onMove(e){
        if (document.body.getAttribute('data-reduced-motion') === '1') return;
        if (!supportsFine) return;
        var w = window.innerWidth || 1;
        var h = window.innerHeight || 1;
        var x = (e.clientX || (e.touches && e.touches[0] && e.touches[0].clientX) || (w/2)) / w;
        var y = (e.clientY || (e.touches && e.touches[0] && e.touches[0].clientY) || (h/2)) / h;
        last.x = x; last.y = y;
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(function(){
                document.documentElement.style.setProperty('--px', Math.round(last.x * 100) + '%');
                document.documentElement.style.setProperty('--py', Math.round(last.y * 100) + '%');
                ticking = false;
            });
        }
    }
    window.addEventListener('mousemove', onMove, { passive: true });
    window.addEventListener('touchmove', onMove, { passive: true });
})();


