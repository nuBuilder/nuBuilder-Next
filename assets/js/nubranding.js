/* Admin tools → Branding (pages/nubranding.php): live preview of logo, title, accent colour and environment label (0.8.297). */
(function () {
	'use strict';
	const $ = (id) => document.getElementById(id);
	const box = $('brandPreview');
	if (!box) return;

	const lum = (hex) => {
		const c = [1, 3, 5].map((i) => parseInt(hex.substr(i, 2), 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)));
		return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
	};
	// as NuBranding::onColor(): white text while the contrast is at least 3:1
	const onColor = (hex) => ((1.05) / (lum(hex) + 0.05) >= 3 ? '#fff' : '#000');

	function accent() {
		if (!$('accent_on')) return;                              // 0.8.632: the accent colour is in the themes
		const on = $('accent_on').checked;
		const c = $('accent').value;
		$('accentHex').textContent = c;
		$('accent').disabled = !on;
		const set = (el, props) => Object.keys(props).forEach((k) => { el.style[k] = on ? props[k] : ''; });
		set($('prevBtn'), { backgroundColor: c, borderColor: c, color: onColor(c) });
		set($('prevBtnOutline'), { borderColor: c, color: c });
		set($('prevLink'), { color: c });
		set($('prevCheck'), { backgroundColor: c, borderColor: c });
	}

	function env() {
		const label = $('env_label').value.trim();
		const badge = $('prevEnv');
		badge.textContent = label;
		badge.hidden = label === '';
		badge.style.backgroundColor = $('env_color').value;
		badge.style.color = onColor($('env_color').value);
	}

	function logo() {
		const input = $('logo_light');
		const remove = $('remove_light');
		const img = $('prevLogo');
		if (input.files && input.files[0]) {
			// as data: URL – the CSP allows img-src 'self' data:, not blob:
			const reader = new FileReader();
			reader.onload = () => { img.src = String(reader.result); };
			reader.readAsDataURL(input.files[0]);
		} else if (remove && remove.checked) {
			img.src = box.dataset.defaultLogo;
		} else {
			img.src = box.dataset.ownLogo || box.dataset.defaultLogo;
		}
	}

	// Show logo (0.8.630): the preview is the top bar – no logo there unless "everywhere"
	function logoShow() {
		$('prevLogo').hidden = $('logo_show').value !== 'all';
		const l = $('show_title_lbl');                     // 0.8.736: "next to the logo" only while the logo is in the top bar
		if (l) l.textContent = l.getAttribute($('logo_show').value === 'all' ? 'data-logo' : 'data-bar');
	}
	$('logo_show').addEventListener('change', logoShow);
	logoShow();

	function title() {
		const t = $('prevTitle'), f = $('title');
		if (f) t.textContent = f.value.trim() || f.getAttribute('data-cfg') || '';
		t.hidden = !$('show_title').checked || t.textContent.trim() === '';
	}

	if ($('accent_on')) {
		['accent_on', 'accent'].forEach((id) => $(id).addEventListener('input', accent));
		$('accent_on').addEventListener('change', accent);
	}
	['env_label', 'env_color'].forEach((id) => $(id).addEventListener('input', env));
	$('logo_light').addEventListener('change', logo);
	if ($('remove_light')) $('remove_light').addEventListener('change', logo);
	$('show_title').addEventListener('change', title);
	if ($('title')) $('title').addEventListener('input', title);
	$('prevLink').addEventListener('click', (e) => e.preventDefault());
	accent(); env(); title();
}());
