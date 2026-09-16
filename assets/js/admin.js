(function () {
	function onReady(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	onReady(function () {
		var input = document.getElementById('wpfoundry-shared-secret');
		var toggle = document.getElementById('wpfoundry-toggle-secret');
		var copy = document.getElementById('wpfoundry-copy-secret');
		if (!input) {
			return;
		}

		if (toggle) {
			toggle.addEventListener('click', function () {
				var shown = input.type === 'text';
				input.type = shown ? 'password' : 'text';
				toggle.textContent = shown
					? toggle.getAttribute('data-show')
					: toggle.getAttribute('data-hide');
			});
		}

		if (copy) {
			copy.addEventListener('click', function () {
				var original = copy.textContent;
				function restore() {
					copy.textContent = original;
					input.type = 'password';
				}

				input.type = 'text';
				input.select();

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(input.value).then(function () {
						copy.textContent = copy.getAttribute('data-copied');
						setTimeout(restore, 1500);
					}).catch(function () {
						copy.textContent = copy.getAttribute('data-failed');
						setTimeout(restore, 1500);
					});
					return;
				}

				try {
					document.execCommand('copy');
					copy.textContent = copy.getAttribute('data-copied');
				} catch (e) {
					copy.textContent = copy.getAttribute('data-failed');
				}
				setTimeout(restore, 1500);
			});
		}
	});
})();
