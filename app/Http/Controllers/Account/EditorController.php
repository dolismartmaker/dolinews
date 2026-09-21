<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Dolinews\Editors\EditorException;
use App\Domain\Dolinews\Editors\EditorService;
use App\Domain\Dolinews\Models\Editor;
use App\Http\Controllers\Concerns\ResolvesUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Editor creation and membership from the account page (SPEC 4.1):
 * the creating account becomes the owner.
 */
class EditorController extends Controller
{
    use ResolvesUser;

    public function __construct(
        private readonly EditorService $editors,
    ) {}

    /**
     * Create an editor.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->requireUser($request);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'url', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
        ]);

        $editor = $this->editors->create($user, $payload);

        return redirect()->route('account.articles')
            ->with('status', 'Éditeur "'.$editor->name.'" créé : vous en êtes le propriétaire.');
    }

    /**
     * Attach a member to an editor the actor owns.
     */
    public function attachMember(Request $request, int $editorId): RedirectResponse
    {
        $user = $this->requireUser($request);

        /** @var Editor|null $editor */
        $editor = Editor::query()->find($editorId);

        abort_if($editor === null, 404);

        $payload = $request->validate([
            'member_email' => ['required', 'email'],
        ]);

        $member = User::query()
            ->where('email', mb_strtolower(trim((string) $payload['member_email'])))
            ->first();

        if ($member === null) {
            return back()->withErrors([
                'member_email' => 'Aucun compte avec cette adresse.',
            ]);
        }

        try {
            $this->editors->attachMember($editor, $user, $member);
        } catch (EditorException $e) {
            return back()->withErrors(['member_email' => $e->getMessage()]);
        }

        return back()->with('status', 'Membre ajouté à l\'éditeur.');
    }
}
