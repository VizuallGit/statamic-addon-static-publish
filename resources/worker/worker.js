/**
 * Static Publish: the Cloudflare Worker.
 *
 * Almost nothing reaches this script. Every page, image, stylesheet and font
 * is answered straight from the uploaded files, and the Worker is never
 * woken. The single exception is a form submission: the static pages post to
 * /!/forms/{handle}, exactly the address they post to on the PHP site, and
 * `run_worker_first` in the generated wrangler config sends that one path
 * here instead of to the file store.
 *
 * What this script does is carry that request to the CMS and carry the answer
 * back. It builds the outgoing request from scratch rather than passing the
 * visitor's own through: the visitor's cookies must not reach the CMS, and
 * the CMS's session cookie must not reach the visitor.
 */

const FORM_PREFIX = '/!/forms/';

export default {
	async fetch(request, env) {
		const url = new URL(request.url);

		if (request.method === 'POST' && url.pathname.startsWith(FORM_PREFIX)) {
			return submitForm(request, url, env);
		}

		return env.ASSETS.fetch(request);
	},
};

async function submitForm(request, url, env) {
	if (!env.CMS_ORIGIN || !env.FORM_SECRET) {
		return json({ error: 'Formularen er ikke sat op.' }, 503);
	}

	// Statamic form handles are word characters. Anything else is not a form
	// we have, and refusing it here keeps the path we build below honest.
	const handle = url.pathname.slice(FORM_PREFIX.length);

	if (!/^[A-Za-z0-9_-]+$/.test(handle)) {
		return json({ error: 'Ukendt formular.' }, 404);
	}

	const headers = new Headers();
	const contentType = request.headers.get('content-type');

	if (contentType) {
		headers.set('content-type', contentType);
	}

	headers.set('accept', 'application/json');

	// Statamic answers a form in JSON, with validation errors per field,
	// only when it takes the request for an AJAX one.
	headers.set('x-requested-with', 'XMLHttpRequest');
	headers.set('x-static-publish-secret', env.FORM_SECRET);

	// The CMS sees every request coming from Cloudflare. Without this its
	// rate limit would count the whole world as one visitor.
	headers.set('x-static-publish-ip', request.headers.get('cf-connecting-ip') || '');

	// Set when the CMS sits behind Cloudflare Access, so the Worker may pass
	// while a browser still meets the login screen.
	if (env.ACCESS_CLIENT_ID && env.ACCESS_CLIENT_SECRET) {
		headers.set('cf-access-client-id', env.ACCESS_CLIENT_ID);
		headers.set('cf-access-client-secret', env.ACCESS_CLIENT_SECRET);
	}

	let response;

	try {
		response = await fetch(`${env.CMS_ORIGIN}/!/static-publish/forms/${handle}`, {
			method: 'POST',
			headers,
			body: request.body,
			redirect: 'manual',
		});
	} catch (error) {
		return json({ error: 'Beskeden kunne ikke sendes. Prøv igen om lidt.' }, 502);
	}

	// Reading the body and building a new response drops every header the CMS
	// set, the session cookie among them. Only the status and the JSON are
	// the visitor's business.
	const body = await response.text();

	return new Response(body, {
		status: response.status,
		headers: {
			'content-type': response.headers.get('content-type') || 'application/json; charset=utf-8',
			'cache-control': 'no-store',
		},
	});
}

function json(body, status) {
	return new Response(JSON.stringify(body), {
		status,
		headers: { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' },
	});
}
