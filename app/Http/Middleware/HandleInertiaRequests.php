<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $quote = str(Inspiring::quotes()->random());
        $user = $request->user();

        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            'quote' => [
                'message' => $quote->before('-')->trim()->value(),
                'author' => $quote->after('-')->trim()->value(),
            ],
            'auth' => [
                // A resource, not the model: the browser has no business
                // receiving timestamps, the remember token or anything else the
                // interface does not draw.
                'user' => $user instanceof User
                    ? (new AuthenticatedUserResource($user))->toArray($request)
                    : null,
            ],
            'flash' => $this->flash($request),
        ]);
    }

    /**
     * One-off messages for the toast in the corner of the screen.
     *
     * The id is what lets the interface tell two identical messages apart —
     * without it, deactivating two accounts in a row would show the notice once
     * and then look as though nothing happened the second time.
     *
     * @return array{success: string|null, error: string|null, id: string|null}
     */
    private function flash(Request $request): array
    {
        $success = $request->session()->get('success');
        $error = $request->session()->get('error');

        return [
            'success' => is_string($success) ? $success : null,
            'error' => is_string($error) ? $error : null,
            'id' => ($success === null && $error === null) ? null : (string) Str::uuid(),
        ];
    }
}
