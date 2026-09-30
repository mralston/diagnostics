/**
 * A minimal HTTP client for the package's JSON API. Uses window.axios when the
 * host has one (Laravel's default bootstrap), otherwise fetch with the XSRF
 * cookie Laravel sets, so a host without axios needs nothing extra.
 */
export function createClient(baseUrl = '/diagnostics') {
    const base = baseUrl.replace(/\/$/, '');

    async function request(method, url, body) {
        if (typeof window !== 'undefined' && window.axios) {
            const response = await window.axios({ method, url, data: body, headers: { Accept: 'application/json' } });
            return response.data;
        }

        const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }
        const xsrf = readCookie('XSRF-TOKEN');
        if (xsrf) {
            headers['X-XSRF-TOKEN'] = xsrf;
        }

        const response = await fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === undefined ? undefined : JSON.stringify(body),
        });

        if (!response.ok) {
            const error = new Error(`Request failed with status ${response.status}`);
            error.status = response.status;
            try {
                error.data = await response.json();
            } catch (e) {
                error.data = null;
            }
            throw error;
        }

        return response.json();
    }

    return {
        suite: (suite, subjectId) => request('GET', `${base}/${suite}/${subjectId}`),
        start: (suite, subjectId) => request('POST', `${base}/${suite}/${subjectId}/runs`, {}),
        runs: (suite, subjectId, page = 1) => request('GET', `${base}/${suite}/${subjectId}/runs?page=${page}`),
        run: (runId) => request('GET', `${base}/runs/${runId}`),
        result: (runId, resultId) => request('GET', `${base}/runs/${runId}/results/${resultId}`),
        fixQuestions: (runId, resultId) => request('GET', `${base}/runs/${runId}/results/${resultId}/fix`),
        fix: (runId, resultId, answers = {}) => request('POST', `${base}/runs/${runId}/results/${resultId}/fix`, { answers }),
    };
}

function readCookie(name) {
    if (typeof document === 'undefined') {
        return null;
    }
    const match = document.cookie.split('; ').find((row) => row.startsWith(name + '='));
    return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : null;
}
