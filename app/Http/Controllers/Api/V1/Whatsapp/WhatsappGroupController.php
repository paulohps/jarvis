<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Actions\Whatsapp\CreateWhatsappGroup;
use App\Actions\Whatsapp\ListWhatsappGroupParticipants;
use App\Actions\Whatsapp\ManageWhatsappGroup;
use App\Exceptions\EvolutionApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsappConversationResource;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The WhatsApp groups of the apps, as on the web: creating one, and, for
 * a group chat, its info and members as WhatsApp has them now, changing
 * its subject, description, picture, permissions and disappearing
 * messages, adding, removing, promoting and demoting members, its invite
 * link and leaving it (ManageWhatsappGroup). Needs the number connected
 * (422 otherwise); what WhatsApp refuses (only an admin may) or a failing
 * WhatsApp is a 502.
 */
class WhatsappGroupController extends Controller
{
    /**
     * `POST /api/v1/whatsapp/groups`: `{ account, subject, members: [friendly
     * id of a contact chat of the number], description? }`. Answers 201
     * with the group's chat.
     *
     * @throws ValidationException
     */
    public function store(Request $request, CreateWhatsappGroup $createGroup): JsonResponse
    {
        $account = WhatsappAccount::query()->where('friendly_id', $request->string('account')->toString())->first();

        if ($account === null) {
            throw ValidationException::withMessages(['account' => __('Pick one of your numbers.')]);
        }

        $request->validate(['members' => ['required', 'array']]);

        $group = $this->onWhatsapp(fn () => $createGroup(
            $this->actor($request),
            $account,
            $request->string('subject')->toString(),
            array_values(array_map(strval(...), (array) $request->input('members'))),
            $request->string('description')->toString(),
        ));

        return (new WhatsappConversationResource($group->load(['account', 'lastChatMessage', 'tags'])))->response()->setStatusCode(201);
    }

    /**
     * `GET /api/v1/whatsapp/conversations/{chat}/group`: the group's info
     * (its subject and description kept), who may send messages and edit
     * the info (`admins_only`, `info_admins_only`), its members (admins
     * first, the number `me`) and whether the number is an admin.
     *
     * @throws ValidationException
     */
    public function show(Request $request, WhatsappTarget $chat, ManageWhatsappGroup $manage, ListWhatsappGroupParticipants $listParticipants): JsonResponse
    {
        $user = $this->actor($request);
        $permissions = $this->onWhatsapp(fn () => $manage->refresh($user, $chat));
        $members = $this->onWhatsapp(fn () => $listParticipants($user, $chat));

        return $this->group($request, $chat, $members, $permissions);
    }

    /**
     * `PATCH /api/v1/whatsapp/conversations/{chat}/group`: any of `{
     * subject, description, admins_only, info_admins_only,
     * ephemeral_seconds (0, 86400, 604800 or 7776000) }`. Answers the group
     * as `show` does.
     *
     * @throws ValidationException
     */
    public function update(Request $request, WhatsappTarget $chat, ManageWhatsappGroup $manage, ListWhatsappGroupParticipants $listParticipants): JsonResponse
    {
        $input = $request->validate([
            'subject' => ['sometimes', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'admins_only' => ['sometimes', 'boolean'],
            'info_admins_only' => ['sometimes', 'boolean'],
            'ephemeral_seconds' => ['sometimes', 'integer'],
        ]);
        $user = $this->actor($request);

        $this->onWhatsapp(function () use ($input, $user, $chat, $manage): void {
            if (array_key_exists('subject', $input)) {
                $manage->rename($user, $chat, $input['subject']);
            }

            if (array_key_exists('description', $input)) {
                $manage->describe($user, $chat, (string) $input['description']);
            }

            if (array_key_exists('admins_only', $input) || array_key_exists('info_admins_only', $input)) {
                $manage->setPermissions(
                    $user,
                    $chat,
                    array_key_exists('admins_only', $input) ? (bool) $input['admins_only'] : null,
                    array_key_exists('info_admins_only', $input) ? (bool) $input['info_admins_only'] : null,
                );
            }

            if (array_key_exists('ephemeral_seconds', $input)) {
                $manage->setTimer($user, $chat, (int) $input['ephemeral_seconds']);
            }
        });

        return $this->show($request, $chat->refresh(), $manage, $listParticipants);
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/group/picture`: multipart
     * `{ file }` (JPEG or PNG). Answers 204; the new picture comes with the
     * chat's next refresh.
     *
     * @throws ValidationException
     */
    public function picture(Request $request, WhatsappTarget $chat, ManageWhatsappGroup $manage): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $this->onWhatsapp(fn () => $manage->changePicture($this->actor($request), $chat, $file));

        return response()->json(null, 204);
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/group/members`: `{ action
     * (add, remove, promote, demote), participants: [number or JID] }`.
     * Answers `{ results: {jid: done} }` (WhatsApp may refuse some, e.g.
     * who only accepts invites) and the members now.
     *
     * @throws ValidationException
     */
    public function members(Request $request, WhatsappTarget $chat, ManageWhatsappGroup $manage, ListWhatsappGroupParticipants $listParticipants): JsonResponse
    {
        $request->validate(['participants' => ['required', 'array']]);
        $user = $this->actor($request);

        $results = $this->onWhatsapp(fn () => $manage->updateMembers(
            $user,
            $chat,
            $request->string('action')->toString(),
            array_values(array_map(strval(...), (array) $request->input('participants'))),
        ));

        return response()->json([
            'results' => (object) $results,
            'members' => $this->onWhatsapp(fn () => $listParticipants($user, $chat)),
        ]);
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/group/invite`: `{ revoke? }`,
     * the link to join the group (a new one with `revoke`). Answers `{ url }`.
     *
     * @throws ValidationException
     */
    public function invite(Request $request, WhatsappTarget $chat, ManageWhatsappGroup $manage): JsonResponse
    {
        $url = $this->onWhatsapp(fn () => $manage->inviteLink($this->actor($request), $chat, $request->boolean('revoke')));

        return response()->json(['url' => $url]);
    }

    /**
     * `POST /api/v1/whatsapp/conversations/{chat}/group/leave`: the number
     * leaves the group; the chat stays, archived. Answers the chat.
     *
     * @throws ValidationException
     */
    public function leave(Request $request, WhatsappTarget $chat, ManageWhatsappGroup $manage): WhatsappConversationResource
    {
        $this->onWhatsapp(fn () => $manage->leave($this->actor($request), $chat));

        return new WhatsappConversationResource($chat->refresh()->load(['account', 'lastChatMessage', 'tags']));
    }

    /**
     * @param  list<array{jid: string, lid: string|null, name: string, number: string|null, admin: bool, me: bool}>  $members
     * @param  array{announce: bool, restrict: bool}  $permissions
     */
    private function group(Request $request, WhatsappTarget $chat, array $members, array $permissions): JsonResponse
    {
        return response()->json(['data' => [
            'chat' => (new WhatsappConversationResource($chat->load(['account', 'lastChatMessage', 'tags'])))->resolve($request),
            'admins_only' => $permissions['announce'],
            'info_admins_only' => $permissions['restrict'],
            'is_admin' => collect($members)->contains(fn (array $member): bool => $member['me'] && $member['admin']),
            'members' => $members,
        ]]);
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $change
     * @return TResult
     */
    private function onWhatsapp(callable $change): mixed
    {
        try {
            return $change();
        } catch (EvolutionApiException $exception) {
            report($exception);

            throw new HttpException(502, __('WhatsApp did not take it. Only an admin may do this, or WhatsApp could not be reached.'), $exception);
        }
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
