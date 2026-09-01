<?php

namespace Pterodactyl\Tests\Integration\Api\Client\Server;

use Pterodactyl\Models\User;
use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Permission;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class ActivityLogControllerTest extends ClientApiIntegrationTestCase
{
    /**
     * The owner of a server sees everything that happened on it: their own actions and
     * those of every sub-user they granted access to.
     */
    public function testServerOwnerSeesTheirOwnAndSubuserActivity()
    {
        [$subuser, $server] = $this->generateTestAccount([Permission::ACTION_ACTIVITY_READ]);
        $owner = $server->user;

        $this->logActivity($server, $owner, 'server:console.command');
        $this->logActivity($server, $subuser, 'server:power.start');

        $response = $this->actingAs($owner)->getJson("/api/client/servers/$server->uuid/activity");
        $response->assertOk();

        $this->assertEqualsCanonicalizing(
            ['server:console.command', 'server:power.start'],
            $response->json('data.*.attributes.event')
        );
    }

    /**
     * `activity.read` hands a sub-user their own audit trail, not the server's. What the
     * owner and the other sub-users did stays out of it.
     */
    public function testSubuserOnlySeesTheirOwnActivity()
    {
        [$subuser, $server] = $this->generateTestAccount([Permission::ACTION_ACTIVITY_READ]);
        $owner = $server->user;
        $other = $this->createSubuser($server, [Permission::ACTION_ACTIVITY_READ]);

        $this->logActivity($server, $owner, 'server:settings.rename');
        $this->logActivity($server, $other, 'server:file.delete');
        $this->logActivity($server, $subuser, 'server:power.restart');

        $response = $this->actingAs($subuser)->getJson("/api/client/servers/$server->uuid/activity");
        $response->assertOk();

        $this->assertSame(['server:power.restart'], $response->json('data.*.attributes.event'));
    }

    /**
     * That restriction is not a side effect of the staff filter, so turning the staff
     * filter off must not hand a sub-user everybody else's activity.
     */
    public function testSubuserRestrictionHoldsWhenStaffHidingIsDisabled()
    {
        config()->set('activity.hide_admin_activity', false);

        [$subuser, $server] = $this->generateTestAccount([Permission::ACTION_ACTIVITY_READ]);
        $owner = $server->user;

        $this->logActivity($server, $owner, 'server:settings.rename');
        $this->logActivity($server, $subuser, 'server:power.restart');

        $response = $this->actingAs($subuser)->getJson("/api/client/servers/$server->uuid/activity");
        $response->assertOk();

        $this->assertSame(['server:power.restart'], $response->json('data.*.attributes.event'));
    }

    /**
     * Actor-less rows are things that happened to the server rather than things another
     * person did, so scoping a sub-user to their own trail must not swallow them: a
     * sub-user who starts a backup still needs to find out that it failed.
     */
    public function testSubuserStillSeesSystemActivity()
    {
        [$subuser, $server] = $this->generateTestAccount([Permission::ACTION_ACTIVITY_READ]);
        $owner = $server->user;

        $this->logActivity($server, $subuser, 'server:backup.start');
        Activity::event('server:backup.fail')->subject($server)->anonymous()->log();
        $this->logActivity($server, $owner, 'server:settings.rename');

        $response = $this->actingAs($subuser)->getJson("/api/client/servers/$server->uuid/activity");
        $response->assertOk();

        $this->assertEqualsCanonicalizing(
            ['server:backup.start', 'server:backup.fail'],
            $response->json('data.*.attributes.event')
        );
    }

    /**
     * Without `activity.read` the endpoint stays shut entirely.
     */
    public function testSubuserWithoutPermissionCannotReadActivity()
    {
        [$subuser, $server] = $this->generateTestAccount([Permission::ACTION_WEBSOCKET_CONNECT]);

        $this->actingAs($subuser)
            ->getJson("/api/client/servers/$server->uuid/activity")
            ->assertStatus(Response::HTTP_FORBIDDEN);
    }

    /**
     * Support staff hold root_admin. What they do while helping a customer is tracked,
     * but it is not part of the customer's own activity feed.
     */
    public function testStaffActivityIsHiddenFromTheServerOwner()
    {
        [$owner, $server] = $this->generateTestAccount();
        $staff = User::factory()->admin()->create();

        $this->logActivity($server, $owner, 'server:power.start');
        $this->logActivity($server, $staff, 'server:file.delete');

        $response = $this->actingAs($owner)->getJson("/api/client/servers/$server->uuid/activity");
        $response->assertOk();

        $this->assertSame(['server:power.start'], $response->json('data.*.attributes.event'));
    }

    /**
     * The hiding is the config flag doing the work, not a side effect of the query. Flip
     * it off and upstream's behavior comes back.
     */
    public function testStaffActivityIsVisibleWhenHidingIsDisabled()
    {
        config()->set('activity.hide_admin_activity', false);

        [$owner, $server] = $this->generateTestAccount();
        $staff = User::factory()->admin()->create();

        $this->logActivity($server, $owner, 'server:power.start');
        $this->logActivity($server, $staff, 'server:file.delete');

        $response = $this->actingAs($owner)->getJson("/api/client/servers/$server->uuid/activity");
        $response->assertOk();

        $this->assertEqualsCanonicalizing(
            ['server:power.start', 'server:file.delete'],
            $response->json('data.*.attributes.event')
        );
    }

    /**
     * Hiding staff activity is about what a customer sees. Staff reading the same endpoint
     * still get the full log, including each other's actions -- this is the only place the
     * panel surfaces an activity log, so hiding it from them too would leave support
     * actions readable only in the database.
     */
    public function testStaffStillSeeEachOthersActivity()
    {
        [$owner, $server] = $this->generateTestAccount();
        $staff = User::factory()->admin()->create();
        $otherStaff = User::factory()->admin()->create();

        $this->logActivity($server, $owner, 'server:power.start');
        $this->logActivity($server, $otherStaff, 'server:file.delete');

        $response = $this->actingAs($staff)->getJson("/api/client/servers/$server->uuid/activity");
        $response->assertOk();

        $this->assertEqualsCanonicalizing(
            ['server:power.start', 'server:file.delete'],
            $response->json('data.*.attributes.event')
        );
    }

    /**
     * A staff member who owns a server is a customer like any other there: hiding staff
     * activity must not blank out their own feed.
     */
    public function testStaffOwnerStillSeesTheirOwnActivity()
    {
        [$owner, $server] = $this->generateTestAccount();
        $owner->update(['root_admin' => true]);

        $this->logActivity($server, $owner, 'server:power.start');

        $response = $this->actingAs($owner)->getJson("/api/client/servers/$server->uuid/activity");
        $response->assertOk();

        $this->assertSame(['server:power.start'], $response->json('data.*.attributes.event'));
    }

    private function createSubuser(Server $server, array $permissions): User
    {
        $user = User::factory()->create();

        Subuser::factory()->create([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'permissions' => $permissions,
        ]);

        return $user;
    }

    private function logActivity(Server $server, User $actor, string $event): void
    {
        Activity::event($event)->subject($server)->actor($actor)->log();
    }
}
