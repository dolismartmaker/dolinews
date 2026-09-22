<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Dolinews\Articles\ArticleException;
use App\Domain\Dolinews\Articles\TranslationMandateService;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Models\Editor;
use App\Domain\Dolinews\Models\Project;
use App\Domain\Dolinews\Models\TranslationMandate;
use App\Domain\Dolinews\Translation\TranslationRouter;
use App\Http\Controllers\Concerns\ResolvesUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The translation screens of the account area (SPEC 5.6/5.7).
 *
 * Three pages rather than one: an entry page naming the two ways an
 * announcement gets translated, the mandates, and the automatic
 * translation. The two ways ADD UP and are never a choice between them -
 * an editor with a Spanish translator under mandate and the service
 * filling in Greek is the normal case, and a single exclusive switch
 * would force it to give up one of the two.
 *
 * The mandate page holds both sides: what the owned editor delegated,
 * and what this account was delegated by others. A translator needs the
 * second as much as an editor needs the first, and splitting them would
 * leave a translator with no page of their own.
 */
class TranslationMandateController extends Controller
{
    use ResolvesUser;

    public function __construct(
        private readonly TranslationMandateService $mandates,
        private readonly EditorService $editors,
        private readonly TranslationRouter $router,
    ) {}

    /**
     * The entry page: the two ways an announcement gets translated,
     * side by side, with what is in force for this account.
     */
    public function index(Request $request): View
    {
        $user = $this->requireUser($request);
        $editor = $this->editors->ownedEditor($user);

        return view('account.translations.index', [
            'editor' => $editor,
            'grantedCount' => $editor !== null
                ? $this->mandates->forEditor($editor)->whereNull('revoked_at')->count()
                : 0,
            'heldCount' => $this->mandates->forTranslator($user)->count(),
            'autoTranslate' => $editor !== null && $editor->auto_translate,
            // The automatic side is only announced where it does
            // something: an instance with no engine would otherwise
            // promise a translation nobody will produce.
            'engineOffered' => $this->engineOffered($editor),
        ]);
    }

    /**
     * Mandates granted by the owned editor, and mandates held here.
     */
    public function mandates(Request $request): View
    {
        $user = $this->requireUser($request);
        $editor = $this->editors->ownedEditor($user);

        return view('account.translations.mandates', [
            'editor' => $editor,
            'granted' => $editor !== null ? $this->mandates->forEditor($editor) : collect(),
            'held' => $this->mandates->forTranslator($user),
            'projects' => $editor !== null
                ? Project::query()->where('editor_id', $editor->getKey())->orderBy('name')->get()
                : collect(),
            'contentLocales' => (array) config('dolinews.content_locales', []),
        ]);
    }

    /**
     * Automatic translation: the opt-in, what is left of the shared
     * allowance this month, and the editor's own key.
     */
    public function automatic(Request $request): View
    {
        $user = $this->requireUser($request);
        $editor = $this->editors->ownedEditor($user);

        return view('account.translations.automatic', [
            'editor' => $editor,
            'engineOffered' => $this->engineOffered($editor),
            'contentLocales' => (array) config('dolinews.content_locales', []),
            // Null and the empty list read the same on screen: every
            // language, which is where an editor starts.
            'wantedLocales' => $editor !== null ? ($editor->translation_locales ?? []) : [],
            'hasOwnKey' => $editor !== null && trim((string) $editor->translation_api_key) !== '',
            'ceiling' => $this->router->ceiling(),
            'spent' => $editor !== null ? $this->router->spent($editor) : 0,
            'remaining' => $editor !== null ? $this->router->remaining($editor) : 0,
            // The allowance is monthly: saying when it comes back is
            // what turns a refusal into information.
            'resetsOn' => now()->startOfMonth()->addMonth(),
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
     * The languages an editor wants its announcements translated into
     * (SPEC 5.7).
     *
     * An empty selection means every language offered, not none: an
     * editor that unticks everything is asking for the default, and
     * reading it as "translate into nothing" would silently turn the
     * feature off on a click meant to reset it.
     */
    public function updateLocales(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);
        $editor = $this->editors->ownedEditor($user);

        if ($editor === null) {
            return back()->withErrors([
                'translation_locales' => __('Confier un mandat suppose de posséder un éditeur.'),
            ]);
        }

        $payload = $request->validate([
            'translation_locales' => ['nullable', 'array'],
            'translation_locales.*' => ['string', 'max:5'],
        ]);

        /** @var array<int, string> $allowed */
        $allowed = (array) config('dolinews.content_locales', []);

        $kept = array_values(array_filter(
            (array) ($payload['translation_locales'] ?? []),
            static fn (mixed $locale): bool => is_string($locale) && in_array($locale, $allowed, true),
        ));

        $editor->translation_locales = $kept === [] ? null : $kept;
        $editor->save();

        return back()->with('status', __('Langues de traduction enregistrées.'));
    }

    /**
     * Store the editor's own translation key, or drop it.
     *
     * An editor on its own key draws on nothing of ours: it pays its
     * supplier and owes the service nothing (SPEC 5.7/12). The key is
     * encrypted at rest and never shown again.
     */
    public function updateKey(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);
        $editor = $this->editors->ownedEditor($user);

        if ($editor === null) {
            return back()->withErrors([
                'translation_api_key' => __('Confier un mandat suppose de posséder un éditeur.'),
            ]);
        }

        $payload = $request->validate([
            'translation_api_key' => ['nullable', 'string', 'max:255'],
        ]);

        $key = trim((string) ($payload['translation_api_key'] ?? ''));

        $editor->translation_api_key = $key !== '' ? $key : null;
        $editor->translation_key_set_at = $key !== '' ? now() : null;
        $editor->save();

        return back()->with('status', $key !== ''
            ? __('Clé enregistrée : vos traductions passent désormais par votre propre compte.')
            : __('Clé retirée : vos traductions repassent par le service.'));
    }

    /**
     * Whether the automatic side has anything to offer this account: an
     * engine of the deployment, or a key of its own.
     */
    private function engineOffered(?Editor $editor): bool
    {
        if ($editor !== null && trim((string) $editor->translation_api_key) !== '') {
            return true;
        }

        return $this->router->sharedEngine()->isAvailable();
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
