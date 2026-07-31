<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class CookieController extends Controller
{
    use RespondsWithJson;

    public function consent(Request $request): JsonResponse|RedirectResponse
    {
        $consent = config('cookies.default_consent');
        $accept = $request->input('accept', '');

        if ($accept === 'all') {
            $consent = [
                'necessary' => true,
                'analytics' => true,
                'preferences' => true,
                'marketing' => true,
            ];
        } elseif ($accept === 'necessary') {
            $consent = array_merge(config('cookies.default_consent'), ['necessary' => true]);
        } else {
            foreach (array_keys(config('cookies.default_consent')) as $cat) {
                $consent[$cat] = $cat === 'necessary'
                    || in_array($request->input($cat), ['true', '1', 1, true], true);
            }
        }

        if (empty($consent['analytics'])) {
            foreach (['_ga', '_gid', '_gat'] as $ga) {
                Cookie::queue(Cookie::forget($ga, '/'));
            }
        }

        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['ok' => true, 'consent' => $consent])
                ->withCookie($this->makeConsentCookie($consent));
        }

        return redirect($request->input('redirect', $request->headers->get('referer', '/')))
            ->withCookie($this->makeConsentCookie($consent));
    }

    public function prefs(Request $request): JsonResponse|RedirectResponse
    {
        $existingPrefs = [];
        $rawPrefs = $request->cookie(config('cookies.names.preferences'), '');
        if ($rawPrefs !== '') {
            $decoded = json_decode($rawPrefs, true);
            if (is_array($decoded)) {
                $existingPrefs = $decoded;
            }
        }

        $allowedKeys = ['theme', 'locale', 'fontSize', 'reducedMotion', 'notifications'];
        $incomingPrefs = [];
        foreach ($allowedKeys as $key) {
            if ($request->has($key)) {
                $incomingPrefs[$key] = $request->input($key);
            }
        }

        $mergedPrefs = array_merge($existingPrefs, $incomingPrefs);

        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['ok' => true, 'prefs' => $mergedPrefs])
                ->withCookie($this->makePreferencesCookie($mergedPrefs));
        }

        return redirect($request->headers->get('referer', '/'))
            ->withCookie($this->makePreferencesCookie($mergedPrefs));
    }

    public function withdraw(Request $request): JsonResponse|RedirectResponse
    {
        $response = response()->noContent();

        Cookie::queue(Cookie::forget(config('cookies.names.consent'), '/'));
        Cookie::queue(Cookie::forget(config('cookies.names.preferences'), '/'));

        foreach (['_ga', '_gid', '_gat', '_fbp', 'fr'] as $cookieName) {
            Cookie::queue(Cookie::forget($cookieName, '/'));
        }

        if ($this->wantsJson($request)) {
            return $this->jsonResponse(['ok' => true, 'message' => 'Consent withdrawn. Cookies cleared.']);
        }

        return redirect($request->input('redirect', '/'));
    }

    public function status(): JsonResponse
    {
        $consent = config('cookies.default_consent');
        $consentGiven = false;
        $rawConsent = request()->cookie(config('cookies.names.consent'), '');
        if ($rawConsent !== '') {
            $decoded = json_decode($rawConsent, true);
            if (is_array($decoded)) {
                $consent = $decoded;
                $consentGiven = true;
            }
        }

        $prefs = [];
        $rawPrefs = request()->cookie(config('cookies.names.preferences'), '');
        if ($rawPrefs !== '') {
            $decoded = json_decode($rawPrefs, true);
            if (is_array($decoded)) {
                $prefs = $decoded;
            }
        }

        return $this->jsonResponse([
            'ok' => true,
            'consentGiven' => $consentGiven,
            'consent' => $consent,
            'prefs' => $prefs,
        ]);
    }

    /**
     * @param  array<string, bool>  $consent
     */
    private function makeConsentCookie(array $consent): \Symfony\Component\HttpFoundation\Cookie
    {
        $minutes = (int) (config('cookies.duration.year') / 60);

        return cookie(
            config('cookies.names.consent'),
            json_encode($consent),
            $minutes,
            '/',
            null,
            app()->environment('production'),
            false,
            false,
            'Lax'
        );
    }

    /**
     * @param  array<string, mixed>  $prefs
     */
    private function makePreferencesCookie(array $prefs): \Symfony\Component\HttpFoundation\Cookie
    {
        $minutes = (int) (config('cookies.duration.year') / 60);

        return cookie(
            config('cookies.names.preferences'),
            json_encode($prefs),
            $minutes,
            '/',
            null,
            app()->environment('production'),
            false,
            false,
            'Lax'
        );
    }
}
