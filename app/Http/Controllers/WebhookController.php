<?php

namespace App\Http\Controllers;

use App\Jobs\DeploySite;
use App\Jobs\RemoveSite;
use App\Jobs\SetupSite;
use App\Project;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function githubPullRequest(Request $request)
    {
        $input = $request->input();
        $signature = $request->header('x-hub-signature');

        abort_unless(isset($input['pull_request']), 200, 'Not a Pull Request');
        abort_unless(isset($input['repository']), 200, 'Not a Repository');
        abort_if($signature === null, 200, 'Signature Required');

        $signature = str_replace('sha1=', '', $signature);
        $pullRequest = $input['pull_request'];
        $projects = Project::where('github_repo', $input['repository']['full_name'])
            ->with('branches')
            ->get()
            ->filter(function ($project) use ($signature) {
                return sha1($project->webhook_secret) === $signature;
            });

        switch ($input['action'] ?? 'other') {
            case 'opened':
            case 'reopened':
                foreach ($projects as $project)
                    SetupSite::dispatch($project, $pullRequest);
                break;
            case 'closed':
                foreach ($projects as $project)
                    RemoveSite::dispatch($project, $pullRequest);
                break;
            case 'synchronize':
                foreach ($projects as $project)
                    DeploySite::dispatch($project->branches()->last(), $pullRequest);
                break;
            case 'assigned':
            case 'unassigned':
            case 'review_requested':
            case 'review_request_removed':
            case 'labeled':
            case 'unlabeled':
            case 'edited':
            default:
                abort(200, 'Not Interested');
        }

        return response()->json(['action' => $input['action']]);
    }
}
