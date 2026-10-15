<?php

namespace Pterodactyl\Http\Controllers\Api\Application\Users;

use Pterodactyl\Models\User;
use Illuminate\Http\Response;
use Pterodactyl\Services\Users\InvitedAccountDeletionService;
use Pterodactyl\Http\Requests\Api\Application\Users\DeleteUserRequest;

class InvitedAccountController
{
    public function __construct(private InvitedAccountDeletionService $deletion)
    {
    }

    public function delete(DeleteUserRequest $request, User $user): Response
    {
        $actor = filter_var($request->header('X-Hostvault-Actor-Id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless($actor, 400, 'An actor is required.');
        $this->deletion->handle($user->id, $actor);

        return response('', 204);
    }
}
