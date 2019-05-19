<?php

namespace Alexusmai\LaravelFileManager\Services\ACLService;

use App\Project;
use Cache;

class ACL
{
    /**
     * @var ACLRepository
     */
    public $aclRepository;

    /**
     * ACL constructor.
     *
     * @param ACLRepository $aclRepository
     */
    public function __construct(ACLRepository $aclRepository)
    {
        $this->aclRepository = $aclRepository;
    }

    /**
     * Get access level for selected path
     *
     * @param        $disk
     * @param string $path
     *
     * @return int
     */
    public function getAccessLevel($disk, $path = '/')
    {
        $pth = explode('/',$path);
        $projects = Project::whereHasMedia($pth)->first();
        $projects->load('members');
        info('authUser:' . auth()->id());
        if ($projects->user_id == auth()->id()) {
            info('creator');
            return 2;
        }

        $members = $projects->members->contains(auth()->id());
        if ($members) {
            info($members);
            return 1;
        }
        $projects->load('contacts');
        $contacts = $projects->contacts->contains(auth()->id());
        if ($contacts) {
            info($contacts);
            return 2;
        }
        return config('file-manager.aclStrategy') === 'blacklist' ? 2 : 0;
       // var_dump(strlen($pth[0]).':'.strlen($pth[1]));
           // Media::inDirectory('uploads', 'foo/bar');
        // get rules list
        $rules = $this->rulesForDisk($disk);

        // find the first rule where the paths are equal
        $firstRule = array_first($rules, function ($value) use ($path) {
            return fnmatch($value['path'], $path);
        });

        if ($firstRule) {
            return $firstRule['access'];
        }

        // positive or negative ACL strategy
        return config('file-manager.aclStrategy') === 'blacklist' ? 2 : 0;
    }

    /**
     * Select rules for disk
     *
     * @param $disk
     *
     * @return array
     */
    protected function rulesForDisk($disk)
    {
        return array_where($this->rulesList(),
            function ($value) use ($disk) {
                return $value['disk'] === $disk;
            });
    }

    /**
     * Get rules list from ACL Repository
     *
     * @return array|mixed
     */
    protected function rulesList()
    {
        // if cache on
        if ($minutes = config('file-manager.aclRulesCache')) {
            $cacheName = 'fm_acl_'.$this->aclRepository->getUserID();

            return Cache::remember($cacheName, $minutes, function () {
                return $this->aclRepository->getRules();
            });
        }

        return $this->aclRepository->getRules();
    }
}
