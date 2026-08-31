const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const test = require( 'node:test' );
const vm = require( 'node:vm' );

const source = `${ fs.readFileSync( 'assets/js/common.js', 'utf8' ) }
globalThis.classifyReferrer = (referrer, pageUrl) => {
	document.referrer = referrer;
	const url = new URL(pageUrl);
	location.host = url.host;
	location.hostname = url.hostname;
	location.search = url.search;
	return bk_postview_main.getReferrerClass();
};`;

const context = {
	Blob,
	URL,
	URLSearchParams,
	bbk_post_view: { api_public: '', post_id: 1, respect_dnt: false },
	document: { referrer: '', visibilityState: 'visible' },
	fetch: () => Promise.resolve(),
	location: { host: 'example.com', hostname: 'example.com', search: '' },
	navigator: { doNotTrack: '0' },
	setTimeout: () => {},
	window: { addEventListener: () => {}, doNotTrack: '0', innerWidth: 1200 },
};

vm.runInNewContext( source, context );

const classify = ( referrer, pageUrl = 'https://example.com/post/' ) =>
	context.classifyReferrer( referrer, pageUrl );

test( 'classifies known AI assistant domains as AI referrals', () => {
	const referrers = [
		'https://chatgpt.com/c/example',
		'https://chat.openai.com/c/example',
		'https://claude.ai/chat/example',
		'https://www.perplexity.ai/search/example',
		'https://copilot.microsoft.com/chats/example',
		'https://gemini.google.com/app/example',
	];

	for ( const referrer of referrers ) {
		assert.equal( classify( referrer ), 'ai', referrer );
	}
} );

test( 'uses explicit AI attribution when the browser omits the referrer', () => {
	assert.equal(
		classify( '', 'https://example.com/post/?utm_source=chatgpt.com' ),
		'ai'
	);
	assert.equal(
		classify( '', 'https://example.com/post/?ref=claude' ),
		'ai'
	);
	assert.equal(
		classify( '', 'https://example.com/post/?utm_source=GEMINI' ),
		'ai'
	);
} );

test( 'keeps search, social, internal and direct traffic in their own buckets', () => {
	assert.equal(
		classify( 'https://www.google.com/search?q=example' ),
		'search'
	);
	assert.equal( classify( 'https://t.co/example' ), 'social' );
	assert.equal( classify( 'https://example.com/another-post/' ), 'internal' );
	assert.equal( classify( '' ), 'direct' );
} );

test( 'does not accept partial-domain false positives', () => {
	assert.equal( classify( 'https://evilchatgpt.com/example' ), 'other' );
	assert.equal( classify( 'https://microsoft.com/example' ), 'other' );
	assert.equal( classify( 'https://notgoogle.example/example' ), 'other' );
} );
