<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $name, string $status = 'active'): Project
    {
        $client = Client::create(['name' => $name.' client']);

        return Project::create(['name' => $name, 'client_id' => $client->id, 'status' => $status]);
    }

    public function test_dashboard_and_projects_have_separate_content_and_navigation(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $p = $this->project('Website');
        $this->actingAs($admin)->get('/')->assertOk()->assertViewIs('dashboard')->assertSee('Recent activity')->assertSee('Upcoming and overdue')->assertDontSee('id="new-project"', false)->assertSee('href="'.route('projects.index').'"', false);
        $this->get('/projects')->assertOk()->assertViewIs('projects')->assertSee('id="new-project"', false)->assertSee('Search projects')->assertDontSee('Recent activity')->assertDontSee('Overdue tasks');
        $this->get('/projects/'.$p->id)->assertOk()->assertSee('href="'.route('projects.index').'"', false);
    }

    public function test_projects_filter_independently_of_dashboard_totals(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $active = $this->project('Active website');
        $complete = $this->project('Complete website', 'completed');
        $archived = $this->project('Archived website', 'archived');
        $active->tasks()->create(['title' => 'Late active task', 'due_date' => today()->subDay()->toDateString()]);
        $archived->tasks()->create(['title' => 'Ignore archived deadline', 'due_date' => today()->subDay()->toDateString()]);
        $this->actingAs($admin)->get('/projects?status=completed')->assertOk()->assertViewHas('projects', fn ($projects) => $projects->count() === 1 && $projects->first()->id === $complete->id)->assertDontSee('<h2>Active website</h2>', false);
        $this->get('/?status=completed&q=missing')->assertViewHas('stats', fn ($stats) => $stats['total'] === 3 && $stats['active'] === 1 && $stats['completed'] === 1 && $stats['overdue'] === 1)->assertSee('Late active task')->assertDontSee('Ignore archived deadline');
    }

    public function test_both_pages_and_dashboard_activity_respect_membership(): void
    {
        $member = User::factory()->create(['role' => 'member', 'active' => true]);
        $visible = $this->project('Assigned website');
        $private = $this->project('Private website');
        $visible->members()->attach($member);
        $visible->activities()->create(['description' => 'Visible update', 'user_id' => $member->id]);
        $private->activities()->create(['description' => 'Secret update', 'user_id' => $member->id]);
        $this->actingAs($member)->get('/')->assertOk()->assertSee('Assigned website')->assertSee('Visible update')->assertDontSee('Private website')->assertDontSee('Secret update')->assertViewHas('stats', fn ($stats) => $stats['total'] === 1);
        $this->get('/projects')->assertOk()->assertSee('Assigned website')->assertDontSee('Private website')->assertDontSee('id="new-project"', false);
    }

    public function test_projects_page_remains_protected(): void
    {
        $this->get('/projects')->assertRedirect('/login');
        $user = User::factory()->create(['role' => 'admin', 'active' => true, 'must_change_password' => true]);
        $this->actingAs($user)->get('/projects')->assertRedirect('/change-password');
    }
}
