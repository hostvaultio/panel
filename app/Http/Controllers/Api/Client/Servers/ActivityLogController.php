<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\ActivityLog;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Transformers\Api\Client\ActivityLogTransformer;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;

class ActivityLogController extends ClientApiController
{
    /**
     * Returns the activity logs for a server.
     */
    public function __invoke(ClientApiRequest $request, Server $server): array
    {
        $this->authorize(Permission::ACTION_ACTIVITY_READ, $server);

        $user = $request->user();
        $isOwner = $user->id === $server->owner_id;

        // `activity.read` hands a sub-user their own audit trail, not the server's:
        // neither the owner's actions nor any other sub-user's. Server-owned events that
        // nobody performed (a backup wings reported as failed) carry no actor and stay
        // visible, since they are not somebody else's activity.
        $restrictToSelf = !$user->root_admin && !$isOwner;

        // Support staff hold root_admin, and what they do while helping a customer is not
        // part of that customer's feed. Gated on the viewer rather than applied globally
        // so staff can still audit staff: this endpoint is the only activity log the panel
        // surfaces, so hiding these rows from admins as well would leave support actions
        // readable only in the database. Only the owner needs the filter -- a sub-user is
        // already pinned to their own rows, and an admin is exempt.
        $hideStaffActivity = !$user->root_admin && $isOwner && config('activity.hide_admin_activity');

        $activity = QueryBuilder::for($server->activity())
            ->with('actor')
            ->allowedSorts(['timestamp'])
            ->allowedFilters([AllowedFilter::partial('event')])
            ->whereNotIn('activity_logs.event', ActivityLog::DISABLED_EVENTS)
            ->when($restrictToSelf, function (Builder $builder) use ($user) {
                $builder->where(function (Builder $builder) use ($user) {
                    $builder->whereMorphedTo('actor', $user)
                        ->orWhereNull('activity_logs.actor_id');
                });
            })
            ->when($hideStaffActivity, function (Builder $builder) use ($server) {
                // We could do this with a query and a lot of joins, but that gets pretty
                // painful so for now we'll execute a simpler query.
                $subusers = $server->subusers()->pluck('user_id')->merge($server->owner_id);

                $builder->select('activity_logs.*')
                    ->leftJoin('users', function (JoinClause $join) {
                        $join->on('users.id', 'activity_logs.actor_id')
                            ->where('activity_logs.actor_type', (new User())->getMorphClass());
                    })
                    ->where(function (Builder $builder) use ($subusers) {
                        $builder->whereNull('users.id')
                            ->orWhere('users.root_admin', 0)
                            ->orWhereIn('users.id', $subusers);
                    });
            })
            ->paginate(min($request->query('per_page', 25), 100))
            ->appends($request->query());

        return $this->fractal->collection($activity)
            ->transformWith($this->getTransformer(ActivityLogTransformer::class))
            ->toArray();
    }
}
