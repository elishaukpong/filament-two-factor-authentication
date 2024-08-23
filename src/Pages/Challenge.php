<?php

namespace Stephenjude\FilamentTwoFactorAuthentication\Pages;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\LoginResponse;
use Illuminate\Contracts\Support\Htmlable;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Stephenjude\FilamentTwoFactorAuthentication\Events\TwoFactorAuthenticationChallenged;
use Stephenjude\FilamentTwoFactorAuthentication\Events\TwoFactorAuthenticationFailed;
use Stephenjude\FilamentTwoFactorAuthentication\TwoFactorAuthenticationProvider;

class Challenge extends BaseSimplePage
{
    protected static string $view = 'filament-two-factor-authentication::pages.challenge';

    public ?array $data = [];

    public function getTitle(): string|Htmlable
    {
        return "Two Factor Authentication";
    }

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());

            return;
        }

        $model = Filament::auth()->getProvider()->getModel();

        $user = $model::find(session('login.id'));

        if (!$user) {
            redirect()->to(filament()->getCurrentPanel()?->getLoginUrl());

            return;
        }

        $this->form->fill();

        TwoFactorAuthenticationChallenged::dispatch($user);
    }

    public function recoveryAction(): Action
    {
        return Action::make('recovery')
            ->link()
            ->label(__('use a recovery code'))
            ->url(
                filament()->getCurrentPanel()->route(
                   'two-factor.recovery'
                )
            );
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);

            $this->form->getState();

            Filament::auth()->loginUsingId(
                id: session('login.id'),
                remember: session('login.remember')
            );

            session()->forget(['login.id', 'login.remember']);

            session()->regenerate();

            event(new ValidTwoFactorAuthenticationCodeProvided(Filament::auth()->user()));

            return app(LoginResponse::class);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }
    }

    /**
     * @return array<int | string, string | Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        TextInput::make('code')
                            ->hiddenLabel()
                            ->hint(
                                __(
                                    'Please confirm access to your account by entering the authentication code provided by your authenticator application.'
                                )
                            )
                            ->label(__('Code'))
                            ->required()
                            ->autocomplete()
                            ->rules([
                                fn() => function (string $attribute, $value, $fail) {
                                    $model = Filament::auth()->getProvider()->getModel();

                                    $user = $model::find(session('login.id'));

                                    if (is_null($user)) {
                                        $fail(__('The provided two factor authentication code was invalid.'));

                                        redirect()->to(filament()->getCurrentPanel()->getLoginUrl());

                                        return;
                                    }

                                    $isValidCode = app(TwoFactorAuthenticationProvider::class)->verify(
                                        secret: decrypt($user->two_factor_secret),
                                        code: $value
                                    );

                                    if (!$isValidCode) {
                                        $fail(__('The provided two factor authentication code was invalid.'));

                                        event(new TwoFactorAuthenticationFailed($user));
                                    }
                                },
                            ]),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    public function form(Form $form): Form
    {
        return $form;
    }

    public function getFormActions(): array
    {
        return [
            $this->getAuthenticateFormAction(),
        ];
    }

    protected function getAuthenticateFormAction(): Action
    {
        return Action::make('authenticate')
            ->label(__('filament-panels::pages/auth/login.form.actions.authenticate.label'))
            ->submit('authenticate');
    }

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }
}