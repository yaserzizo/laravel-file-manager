<?php

namespace Alexusmai\LaravelFileManager\Services\ACLService;

use App\Directory;
use App\DirectoryUser;
use App\Project;
use App\Task;
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

        $ts = explode('/',$path);
        if (count($ts) > 1) {
            $tmp = explode('_',$ts[1]);
            if (count($tmp) > 1 && $tmp[0] == 'task') {

                $task = Task::find($tmp[1]);
                if ($task) {
                    if ($task->main_status == 1) {
                        info('return 1');
                        return 1;
                    }
                }
            }
        }
        info('gooo');
        if (count($ts) > 2) {
            $path = $ts[0] . '/' . $ts[1];
        }
        $dr = Directory::where('name',$path)->first();
        if ($dr) {
            if ($dr->creator_id == auth()->id()) {
                return 2;
            }
        }

        $du = DirectoryUser::where('user_id',auth()->id())->where('path',$path)->max('access');
        info(auth()->id());
      info('path iss :' . $path);
       info('path iss :' . $path.':'.$du);
        if ($du) {
           // info($du);
            return $du;
        }
        return config('file-manager.aclStrategy') === 'blacklist' ? 2 : 1;
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
