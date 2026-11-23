<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Tenancy\WorkspaceProvisioner;
use App\Services\Tenancy\WorkspaceProvisioningException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;

class CustomerWorkspaceController extends Controller
{
    public function create(Request $request): Response
    {
        $user = $this->customer($request);

        return $this->form($user, '', (string) Str::uuid());
    }

    public function store(Request $request, WorkspaceProvisioner $provisioner): RedirectResponse|Response
    {
        $user = $this->customer($request);
        $language = $this->language($user);
        $name = $request->input('name');
        $key = $request->input('request_key');
        $validKey = is_string($key)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $key) === 1;
        $validator = Validator::make(['name' => $name, 'request_key' => $key], [
            'name' => [function (string $attribute, mixed $value, \Closure $fail) use ($language): void {
                if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')
                    || preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 0) {
                    $fail(__('customer.name_error', [], $language));

                    return;
                }
                $normalized = preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $value);
                if ($normalized === null || $normalized === '' || mb_strlen($normalized, 'UTF-8') > 255) {
                    $fail(__('customer.name_error', [], $language));
                }
            }],
            'request_key' => [function (string $attribute, mixed $value, \Closure $fail) use ($validKey, $language): void {
                if (! $validKey) {
                    $fail(__('customer.key_error', [], $language));
                }
            }],
        ]);
        // These implicit required rules also run for absent/null/empty inputs.
        $validator->addRules(['name' => ['required'], 'request_key' => ['required']]);
        $validator->setCustomMessages([
            'name.required' => __('customer.name_error', [], $language),
            'request_key.required' => __('customer.key_error', [], $language),
        ]);
        if ($validator->fails()) {
            return $this->form($user, $name, $validKey ? $key : (string) Str::uuid(), 422, $validator->errors());
        }

        try {
            // Neither request ownership fields nor the hidden retry key grant authority.
            $result = $provisioner->provisionCustomer(DB::connection(), (int) $user->getKey(), $name, $key);
        } catch (WorkspaceProvisioningException $exception) {
            if ($exception->reason() === 'creator_unavailable') {
                return response(__('customer.forbidden', [], $language), 403)->withHeaders($this->privateHeaders());
            }
            if ($exception->reason() === 'request_conflict') {
                return $this->form($user, $name, (string) Str::uuid(), 409, null, 'conflict');
            }
            if ($exception->reason() === 'invalid_input') {
                return $this->form($user, $name, $key, 422, new MessageBag([
                    'name' => __('customer.name_error', [], $language),
                ]));
            }

            return $this->form($user, $name, $key, 503, null, 'unavailable');
        }

        return redirect()->route('customer.home')
            ->with('customer_workspace_status', $result->wasCreated() ? 'created' : 'existing')
            ->withHeaders($this->privateHeaders());
    }

    private function customer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasSafeIdentityId() && $user->isCustomer() && $user->canAuthenticate(), 403);

        return $user;
    }

    private function form(User $user, mixed $name, string $key, int $status = 200, ?MessageBag $errors = null, ?string $formError = null): Response
    {
        // Avoid rendering malformed input shapes/encoding or raw control bytes.
        $displayName = is_string($name) && mb_check_encoding($name, 'UTF-8')
            && preg_match('/[\p{Cc}\p{Cf}]/u', $name) === 0 ? $name : '';

        return response()->view('customer.workspaces.create', [
            'accountName' => $user->name,
            'language' => $this->language($user),
            'name' => $displayName,
            'requestKey' => $key,
            'errors' => $errors ?? new MessageBag,
            'formError' => $formError,
        ], $status)->withHeaders($this->privateHeaders());
    }

    private function language(User $user): string
    {
        return in_array($user->preferred_language, User::SUPPORTED_LANGUAGES, true) ? $user->preferred_language : 'sw';
    }

    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer'];
    }
}
