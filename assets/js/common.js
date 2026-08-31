const bk_postview_main = {
	time_delay: 8000,
	end_point: null,
	post_id: null,
	init: function () {
		this.end_point = bbk_post_view.api_public;
		this.post_id = bbk_post_view.post_id;

		setTimeout(() => {
			bk_postview_main.setPostView();
		}, this.time_delay);
	},
	// Bucketed device width — never the exact pixel width (fingerprinting vector).
	getViewportBucket: function () {
		const w = window.innerWidth;
		if (w < 576) return '<576';
		if (w < 992) return '576-991';
		if (w < 1400) return '992-1399';
		return '>=1400';
	},
	// Classified referrer — never the raw host or full URL, only one of a fixed set of buckets.
	getReferrerClass: function () {
		const AI_DOMAINS = [
			'chatgpt.com',
			'chat.openai.com',
			'claude.ai',
			'perplexity.ai',
			'copilot.microsoft.com',
			'gemini.google.com',
		];
		const AI_ATTRIBUTION_VALUES = [
			'chatgpt',
			'chatgpt.com',
			'openai',
			'claude',
			'claude.ai',
			'perplexity',
			'perplexity.ai',
			'copilot',
			'copilot.microsoft.com',
			'gemini',
			'gemini.google.com',
		];
		const SEARCH_DOMAINS = ['bing.com', 'duckduckgo.com', 'yahoo.com', 'baidu.com'];
		const SOCIAL_DOMAINS = [
			'facebook.com',
			'twitter.com',
			'x.com',
			't.co',
			'linkedin.com',
			'instagram.com',
			'reddit.com',
		];
		const matchesDomain = (host, domain) => host === domain || host.endsWith(`.${domain}`);
		const matchesAnyDomain = (host, domains) => domains.some((domain) => matchesDomain(host, domain));
		const attribution = new URLSearchParams(location.search);
		const attributedSource = (attribution.get('utm_source') || attribution.get('ref') || '').toLowerCase().trim();

		if (AI_ATTRIBUTION_VALUES.includes(attributedSource)) return 'ai';

		const ref = document.referrer;
		if (!ref) return 'direct';

		let host;
		try {
			host = new URL(ref).hostname.toLowerCase().replace(/\.$/, '');
		} catch (e) {
			return 'other';
		}

		const currentHost = location.hostname.toLowerCase().replace(/\.$/, '');

		if (host === currentHost) return 'internal';

		if (matchesAnyDomain(host, AI_DOMAINS)) return 'ai';
		if (matchesAnyDomain(host, SEARCH_DOMAINS) || /(^|\.)google\.[a-z.]+$/.test(host) || /(^|\.)yandex\.[a-z.]+$/.test(host)) return 'search';
		if (matchesAnyDomain(host, SOCIAL_DOMAINS) || /(^|\.)pinterest\.[a-z.]+$/.test(host)) return 'social';
		return 'other';
	},
	// DNT is a per-navigator legacy signal; globalPrivacyControl is the modern
	// successor (Sec-GPC). Either one means the visitor asked not to be tracked.
	hasPrivacySignal: function () {
		return navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true;
	},
	setPostView: function () {
		// Skip background/prerendered tabs: they are not a real view yet.
		if (document.visibilityState !== 'visible') {
			return;
		}

		const url = `${this.end_point}/set-post-views`;
		const data = { post_id: this.post_id };

		// The view count itself stays anonymous either way (no IP/UA stored);
		// only the optional session dimensions are skipped for this visit.
		if (!bbk_post_view.respect_dnt || !this.hasPrivacySignal()) {
			data.viewport = this.getViewportBucket();
			data.referrer = this.getReferrerClass();
		}

		if (navigator.sendBeacon) {
			const blob = new Blob([JSON.stringify(data)], { type: 'application/json' });
			navigator.sendBeacon(url, blob);
			return;
		}

		fetch(url, {
			method: 'POST',
			keepalive: true,
			body: JSON.stringify(data),
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
		}).catch(() => {});
	},
};

window.addEventListener('load', () => bk_postview_main.init());
