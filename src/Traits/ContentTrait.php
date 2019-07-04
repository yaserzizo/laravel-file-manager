<?php

namespace Alexusmai\LaravelFileManager\Traits;

use Alexusmai\LaravelFileManager\Services\ACLService\ACL;
//use Spatie\UrlSigner\Laravel\UrlSignerFacade;
use App\Media;
use \Illuminate\Support\Facades\URL;
use Storage;

trait ContentTrait
{

    /**
     * Get content for the selected disk and path
     *
     * @param      $disk
     * @param null $path
     *
     * @return array
     */
    public function getContent($disk, $path = null)
    {
        $content = Storage::disk($disk)->listContents($path);

        // get a list of directories
        $directories = $this->filterDir($disk, $content);
        foreach ($directories as $key => $val) {
            $directories[$key]['creator'] = '-';
        }

        // get a list of files
        $files = $this->filterFile($disk, $content);
        //$files=null;
        info('yesy');
        $i=0;
        foreach($files as $key => $csm)
        {
            $files[$key]['durl'] = URL::temporarySignedRoute('file.download',now()->addHours(5),['disk'=>$disk,'path'=>$files[$key]['path']]);
            $files[$key]['surl'] = URL::temporarySignedRoute('file.stream',now()->addHours(5),['disk'=>$disk,'path'=>$files[$key]['path']]);
            $files[$key]['purl'] = URL::temporarySignedRoute('file.prev',now()->addHours(5),['disk'=>$disk,'path'=>$files[$key]['path']]);
            $files[$key]['thurl'] = URL::temporarySignedRoute('file.thump',now()->addHours(5),['disk'=>$disk,'path'=>$files[$key]['path']]);
            //$files[$key]['creator']='محمد'.$i;
            $files[$key]['creator']='-';
            $media = Media::where('directory',$files[$key]['dirname'])->where('filename',$files[$key]['filename'])->first();
            if ($media) {
                if ($media->creator_id) {
                    $media->load('creator');
                    $files[$key]['creator']=$media->creator->name;
                }

            }
            $i++;
        }
       // var_dump($files);
        return compact('directories', 'files');
    }

    /**
     * Get directories with properties
     *
     * @param      $disk
     * @param null $path
     *
     * @return array
     */
    public function directoriesWithProperties($disk, $path = null)
    {
        $content = Storage::disk($disk)->listContents($path);

        return $this->filterDir($disk, $content);
    }

    /**
     * Get files with properties
     *
     * @param      $disk
     * @param null $path
     *
     * @return array
     */
    public function filesWithProperties($disk, $path = null)
    {
        $content = Storage::disk($disk)->listContents($path);

        return $this->filterFile($disk, $content);
    }

    /**
     * Get directories for tree module
     *
     * @param $disk
     * @param $path
     *
     * @return array
     */
    public function getDirectoriesTree($disk, $path = null)
    {
        $directories = $this->directoriesWithProperties($disk, $path);

        foreach ($directories as $index => $dir) {
            $directories[$index]['props'] = [
                'hasSubdirectories' => Storage::disk($disk)
                    ->directories($dir['path']) ? true : false,
            ];
        }

        return $directories;
    }

    /**
     * File properties
     *
     * @param      $disk
     * @param null $path
     *
     * @return mixed
     */
    public function fileProperties($disk, $path = null)
    {
        $file = Storage::disk($disk)->getMetadata($path);

        $pathInfo = pathinfo($path);

        $file['basename'] = $pathInfo['basename'];
        $file['basesurl'] = 'test';
        $file['dirname'] = $pathInfo['dirname'] === '.' ? ''
            : $pathInfo['dirname'];
        $file['extension'] = isset($pathInfo['extension'])
            ? $pathInfo['extension'] : '';
        $file['filename'] = $pathInfo['filename'];
        $file['durl'] = URL::temporarySignedRoute('file.download',now()->addHours(5),['disk'=>$disk,'path'=>$path]);
        $file['surl'] = URL::temporarySignedRoute('file.stream',now()->addHours(5),['disk'=>$disk,'path'=>$path]);
        $file['purl'] = URL::temporarySignedRoute('file.prev',now()->addHours(5),['disk'=>$disk,'path'=>$path]);
        $file['thurl'] =  URL::temporarySignedRoute('file.thump',now()->addHours(5),['disk'=>$disk,'path'=>$path]);


        // if ACL ON
        if (config('file-manager.acl')) {
            return $this->aclFilter($disk, [$file])[0];
        }

        return $file;
    }

    /**
     * Get properties for the selected directory
     *
     * @param      $disk
     * @param null $path
     *
     * @return mixed
     */
    public function directoryProperties($disk, $path = null)
    {
        $directory = Storage::disk($disk)->getMetadata($path);

        $pathInfo = pathinfo($path);

        $directory['basename'] = $pathInfo['basename'];
        $directory['dirname'] = $pathInfo['dirname'] === '.' ? ''
            : $pathInfo['dirname'];

        // if ACL ON
        if (config('file-manager.acl')) {
            return $this->aclFilter($disk, [$directory])[0];
        }

        return $directory;
    }

    /**
     * Get only directories
     *
     * @param $content
     *
     * @return array
     */
    protected function filterDir($disk, $content)
    {
        // select only dir
        $dirsList = array_where($content, function ($item) {
            return $item['type'] === 'dir';
        });

        // remove 'filename' param
        $dirs = array_map(function ($item) {
            return array_except($item, ['filename']);
        }, $dirsList);

        // if ACL ON
        if (config('file-manager.acl')) {
            return array_values($this->aclFilter($disk, $dirs));
        }

        return array_values($dirs);
    }

    /**
     * Get only files
     *
     * @param $disk
     * @param $content
     *
     * @return array
     */
    protected function filterFile($disk, $content)
    {
        // select only files
        $files = array_where($content, function ($item) {
            return $item['type'] === 'file';
        });

        // if ACL ON
        if (config('file-manager.acl')) {
            return array_values($this->aclFilter($disk, $files));
        }

        return array_values($files);
    }

    /**
     * ACL filter
     *
     * @param $disk
     * @param $content
     *
     * @return mixed
     */
    protected function aclFilter($disk, $content)
    {
        $acl = resolve(ACL::class);

        $withAccess = array_map(function ($item) use ($acl, $disk) {
            // add acl access level
            $item['acl'] = $acl->getAccessLevel($disk, $item['path']);

            return $item;
        }, $content);

        // filter files and folders
        if (config('file-manager.aclHideFromFM')) {
            return array_filter($withAccess, function ($item) {
                return $item['acl'] !== 0;
            });
        }

        return $withAccess;
    }
}
