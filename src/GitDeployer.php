<?php

namespace SSD;

class GitDeployer
{
    public static function ensureRepo($repo_path, $github_user, $github_token, $github_repo, $branch = 'main')
    {
        $remote_url   = "https://$github_user:$github_token@github.com/$github_repo.git";
        $repo_path_quoted = escapeshellarg($repo_path);
        $git = '/usr/bin/git';

        if (!is_dir("$repo_path/.git")) {
            \SSD\FolderHelper::delete_folder($repo_path);
            mkdir($repo_path, 0755, true);

            $clone_cmd = "$git clone $remote_url $repo_path_quoted";
            exec($clone_cmd . " 2>&1", $output, $status);
            if ($status !== 0) {
                error_log("Git clone failed:\n" . implode("\n", $output));
                return false;
            }

            $checkout_cmd = "cd $repo_path_quoted && $git checkout $branch || $git checkout -b $branch";
            exec($checkout_cmd);
        }

        return true;
    }

    public static function deploy($dist_path)
    {
        $repo_path = dirname($dist_path);
        $repo_path_quoted = escapeshellarg($repo_path);
        $git = '/usr/bin/git';
        $branch = 'main';

        $cmd = implode(" && ", [
            "cd $repo_path_quoted",
            "$git add dist",
            "$git commit -m 'Update static site' || echo 'No changes to commit.'",
            "$git push origin $branch"
        ]);

        exec($cmd . " 2>&1", $output, $status);

        if ($status !== 0) {
            error_log("Git command failed:\n$cmd\nOutput:\n" . implode("\n", $output));
        } else {
            error_log("Git deploy successful:\n" . implode("\n", $output));
        }
    }
}