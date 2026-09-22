<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Articles\TranslationMandateService;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\TranslationMandate;
use App\Domain\Dolinews\Translation\TranslationEngine;
use App\Http\Controllers\Concerns\ResolvesUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Translation mandates from the account area (SPEC 5.6).
 *
 * Two sides on one screen: what the editor this account owns has
 * delegated, and what this account has been delegated by others. A
 * translator needs to see the second as much as an editor needs to see
 * the first, and splitting them would leave a translator with no page of
 * their own.
 */
class TranslationMandateController extends Controller
{
    use ResolvesUser;

    public function __construct(
        private readonly TranslationMandateService $mandates,
        private readonly EditorService $editors,
        private readonly TranslationEngine $engine,
    ) {}

    /**
     * Mandates granted by the owned editor, and mandates held here.
     */
    public function index(Request $request): View
    {
        $user = $this->requireUser($request);
        $editor = $this->editors->ownedEditor($user);

        return view('account.translations', [
            'editor' => $editor,
            'granted' => $editor !== null ? $this->mandates->forEditor($editor) : collect(),
            'held' => $this->mandates->forTranslator($user),
            'projects' => $editor !== null
                ? Project::query()->where('editor_id', $editor->getKey())->orderBy('name')->get()
                : collect(),
            'contentLocales' => (array) config('dolinews.content_locales', []),
            // The switch is only offered where it does something: an
            // instance with no engine configured would otherwise show a
            // checkbox that promises a translation nobody will produce.
            'engineAvailable' => $this->engine->isAvailable(),
        ]);
    }

    /**
     * Turn machine translation on or off for the owned editor
     * (SPEC 5.7). Opt-in, never on by default: what the engine produces
     * goes out under the editor's name.
     */
    public function updateAutoTranslation(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);
        $editor = $this->editors->ownedEditor($user);

        if ($editor === null) {
            return back()->withErrors([
                'auto_translate' => __('Confier un mandat suppose de posséder un éditeur.'),
            ]);
        }

        $editor->auto_translate = $request->boolean('auto_translate');
        $editor->save();

        return back()->with('status', $editor->auto_translate
            ? __('Traduction automatique activée pour vos prochaines annonces.')
            : __('Traduction automatique désactivée.'));
    }

    /**
     * Delegate the right to translate to another contributor account.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);
        $editor = $this->editors->ownedEditor($user);

        if ($editor === null) {
            return back()->withErrors([
                'translator_email' => __('Confier un mandat suppose de posséder un éditeur.'),
            ]);
        }

        $payload = $request->validate([
            'translator_email' => ['required', 'email'],
            'project_id' => ['nullable', 'integer'],
            'locales' => ['nullable', 'array'],
            'locales.*' => ['string', 'max:5'],
        ]);

        $translator = User::query()
            ->where('email', mb_strtolower(trim((string) $payload['translator_email'])))
            ->first();

        if ($translator === null) {
            return back()->withErrors([
                'translator_email' => __('Aucun compte avec cette adresse.'),
            ]);
        }

        $project = null;

        if (($payload['project_id'] ?? null) !== null) {
            /** @var Project|null $project */
            $project = Project::query()->find((int) $payload['project_id']);
        }

        try {
            $this->mandates->grant(
                $editor,
                $user,
                $translator,
                $project,
                /** @var array<int, string>|null */
                $payload['locales'] ?? null,
            );
        } catch (ArticleException $e) {
            return back()->withErrors(['translator_email' => $e->getMessage()]);
        }

        return back()->with('status', __('Mandat de traduction confié.'));
    }

    /**
     * Withdraw a mandate. The row stays readable afterwards.
     */
    public function destroy(Request $request, int $mandateId): RedirectResponse
    {
        $user = $this->requireUser($request);

        /** @var TranslationMandate|null $mandate */
        $mandate = TranslationMandate::query()->find($mandateId);

        abort_if($mandate === null, 404);

        try {
            $this->mandates->revoke($mandate, $user);
        } catch (ArticleException $e) {
            return back()->withErrors(['mandate' => $e->getMessage()]);
        }

        return back()->with('status', __('Mandat de traduction retiré.'));
    }
}
