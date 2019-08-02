<?php

namespace Alexusmai\LaravelFileManager;

use Alexusmai\LaravelFileManager\Events\Deleted;
use Alexusmai\LaravelFileManager\Traits\CheckTrait;
use Alexusmai\LaravelFileManager\Traits\ContentTrait;
use Alexusmai\LaravelFileManager\Traits\PathTrait;
use Alexusmai\LaravelFileManager\Services\TransferService\TransferFactory;
use App\Directory;
use App\Project;
use App\Task;
use App\TaskUser;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use App\Media;
use Plank\Mediable\MediaUploader;
use Plank\Mediable\MediaUploaderFacade;
use Storage;
use Image;

class FileManager
{
    use PathTrait, ContentTrait, CheckTrait;

    /**
     * Initialize App
     *
     * @return array
     */
    public function initialize($path)
    {
        // if config not found
        if (!config()->has('file-manager')) {
            return [
                'result' => [
                    'status'  => 'danger',
                    'message' => trans('file-manager::response.noConfig'),
                ],
            ];
        }

        $config = array_only(config('file-manager'), [
            'acl',
            'leftDisk',
            'rightDisk',
            'leftPath',
            'rightPath',
            'windowsConfig',
        ]);
        $config['lefttDisk'] = $path;
       $config['rightDisk'] = $path;
        $config['leftPath'] = $path;
        $config['rightPath'] = $path;

        // disk list
        foreach (config('file-manager.diskList') as $disk) {
            if (array_key_exists($disk, config('filesystems.disks'))) {
                $config['disks'][$disk] = array_only(
                    config('filesystems.disks')[$disk], ['driver']
                );
            }
        }

        // get language
        $config['lang'] = app()->getLocale();
        //var_dump($config);
        return [
            'result' => [
                'status'  => 'success',
                'message' => null,
            ],
            'config' => $config,
        ];
    }

    /**
     * Get files and directories for the selected path and disk
     *
     * @param $disk
     * @param $path
     *
     * @return array
     */
    public function content($disk, $path)
    {
        // get content for the selected directory
        $content = $this->getContent($disk, $path);

        return [
            'result'      => [
                'status'  => 'success',
                'message' => null,
            ],
            'directories' => $content['directories'],
            'files'       => $content['files'],
        ];
    }

    /**
     * Get part of the directory tree
     *
     * @param $disk
     * @param $path
     *
     * @return array
     */
    public function tree($disk, $path)
    {
        $directories = $this->getDirectoriesTree($disk, $path);

        return [
            'result'      => [
                'status'  => 'success',
                'message' => null,
            ],
            'directories' => $directories,
        ];
    }

    /**
     * Upload files
     *
     * @param $disk
     * @param $path
     * @param $files
     * @param $overwrite
     *
     * @return array
     */
    public function upload($disk, $path, $files, $overwrite)
    {
        $project = null;
        $task_id = null;
        $pt = explode('/', $path);
        $p = explode('_',$pt[0]);
        if (count($p)==2) {
            $project = Project::where('code',$p[1])->first();
        }
        if (count($pt)==2) {
            $task = Task::findOrFail(explode('_',$pt[1])[1]);
            $task_id = $task->id;
        }

        if($pt[0])
        foreach ($files as $file) {
            // skip or overwrite files
            if (!$overwrite
                && Storage::disk($disk)
                    ->exists($path . '/' . $file->getClientOriginalName())
            ) {
                $uploader = MediaUploaderFacade::fromSource($file)
                    ->toDestination('private', $path)
                    ->onDuplicateIncrement()->upload();
/*                $dir = Directory::firstOrCreate(
                    ['name' => $uploader->getDiskPath()],
                    ['disk' => 'private']
                );*/
                if ($project) {

                    $project->attachMedia($uploader, 'task_' . $task_id);
                }

                continue;
            }


            // overwrite or save file
            if (Storage::disk($disk)
                ->exists($path . '/' . $file->getClientOriginalName())
            ) {


            Storage::disk($disk)->putFileAs(
                $path,
                $file,
                $file->getClientOriginalName()
            );

               // $media = MediaUploaderFacade::importPath($disk, $path . '/' . $file->getClientOriginalName());
               $media = Media::forPathOnDisk('private', $path . '/' . $file->getClientOriginalName())->first();
                MediaUploaderFacade::update($media);
                continue;
        }
            $uploader = MediaUploaderFacade::fromSource($file)
                ->toDestination('private', $path)
                ->onDuplicateReplace()->upload();
/*            $dir = Directory::firstOrCreate(
                ['name' => $uploader->getDiskPath()],
                ['disk' => 'private']
            );*/
            if ($project) {

                $project->attachMedia($uploader, 'task_' . $task_id);
            }



        }

        return [
            'result' => [
                'status'  => 'success',
                'message' => trans('file-manager::response.uploaded'),
            ],
        ];
    }

    /**
     * Delete files and folders
     *
     * @param $disk
     * @param $items
     *
     * @return array
     */
    public function delete($disk, $items)
    {
        $deletedItems = [];

        foreach ($items as $item) {
            // check all files and folders - exists or no
            if (!Storage::disk($disk)->exists($item['path'])) {
                continue;
            } else {
                if ($item['type'] === 'dir') {
                    // delete directory
                    return [
                        'result' => [
                            'status'  => 'error',
                            'message' => trans('file-manager::response.aclError'),
                        ],
                    ];
                    Storage::disk($disk)->deleteDirectory($item['path']);
                } else {
                    // delete file
                    $media = Media::forPathOnDisk('private', $item['path'])->first();


                    $media->delete();
                   // Storage::disk($disk)->delete($item['path']);
                }
            }

            // add deleted item
            $deletedItems[] = $item;
        }

        event(new Deleted($disk, $deletedItems));

        return [
            'result' => [
                'status'  => 'success',
                'message' => trans('file-manager::response.deleted'),
            ],
        ];
    }

    /**
     * Copy / Cut - Files and Directories
     *
     * @param $disk
     * @param $path
     * @param $clipboard
     *
     * @return array
     */
    public function paste($disk, $path, $clipboard)
    {
        // compare disk names
        if ($disk !== $clipboard['disk']) {

            if (!$this->checkDisk($clipboard['disk'])) {
                return $this->notFoundMessage();
            }
        }

        $transferService = TransferFactory::build($disk, $path, $clipboard);

        return $transferService->filesTransfer();
    }

    /**
     * Rename file or folder
     *
     * @param $disk
     * @param $newName
     * @param $oldName
     *
     * @return array
     */
    public function rename($disk, $newName, $oldName)
    {

        $media = Media::forPathOnDisk('private', $oldName)->first();
        info('oldName:'.$oldName);
        $media->rename(basename($newName));

      //  Storage::disk($disk)->move($oldName, $newName);

        return [
            'result' => [
                'status'  => 'success',
                'message' => trans('file-manager::response.renamed'),
            ],
        ];
    }

    /**
     * Download selected file
     *
     * @param $disk
     * @param $path
     *
     * @return mixed
     */
    public function download($disk, $path)
    {
        // if file name not in ASCII format
        if (!preg_match('/^[\x20-\x7e]*$/', basename($path))) {
            $filename = Str::ascii(basename($path));
        } else {
            $filename = basename($path);
        }

        return  Storage::disk($disk)->download($path, $filename);//Response::download($path);
    }

    /**
     * Create thumbnails
     *
     * @param $disk
     * @param $path
     *
     * @return \Illuminate\Http\Response|mixed
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function thumbnails($disk, $path)
    {
        // create thumbnail
        if (config('file-manager.cache')) {
            $thumbnail = Image::cache(function ($image) use ($disk, $path) {
                $image->make(Storage::disk($disk)->get($path))->fit(80);
            }, config('file-manager.cache'));

            // output
            return response()->make(
                $thumbnail,
                200,
                ['Content-Type' => Storage::disk($disk)->mimeType($path)]
            );
        }

        $thumbnail = Image::make(Storage::disk($disk)->get($path))->fit(80);

        return $thumbnail->response();
    }

    /**
     * Image preview
     *
     * @param $disk
     * @param $path
     *
     * @return mixed
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function preview($disk, $path)
    {
        // get image
        $preview = Image::make(Storage::disk($disk)->get($path));

        return $preview->response();
    }

    /**
     * Get file URL
     *
     * @param $disk
     * @param $path
     *
     * @return array
     */
    public function url($disk, $path)
    {
        return [
            'result' => [
                'status'  => 'success',
                'message' => null,
            ],
            'url'    => Storage::disk($disk)->url($path),
        ];
    }

    /**
     * Create new directory
     *
     * @param $disk
     * @param $path
     * @param $name
     *
     * @return array
     */
    public function createDirectory($disk, $path, $name)
    {
        // path for new directory
        $directoryName = $this->newPath($path, $name);

        // check - exist directory or no
        if (Storage::disk($disk)->exists($directoryName)) {
            return [
                'result' => [
                    'status'  => 'warning',
                    'message' => trans('file-manager::response.dirExist'),
                ],
            ];
        }

        // create new directory
        Storage::disk($disk)->makeDirectory($directoryName);

        // get directory properties
        $directoryProperties = $this->directoryProperties(
            $disk,
            $directoryName
        );

        // add directory properties for the tree module
        $tree = $directoryProperties;
        $tree['props'] = ['hasSubdirectories' => false];

        return [
            'result'    => [
                'status'  => 'success',
                'message' => trans('file-manager::response.dirCreated'),
            ],
            'directory' => $directoryProperties,
            'tree'      => [$tree],
        ];
    }

    /**
     * Create new file
     *
     * @param $disk
     * @param $path
     * @param $name
     *
     * @return array
     */
    public function createFile($disk, $path, $name)
    {
        // path for new file
        $path = $this->newPath($path, $name);

        // check - exist file or no
        if (Storage::disk($disk)->exists($path)) {
            return [
                'result' => [
                    'status'  => 'warning',
                    'message' => trans('file-manager::response.fileExist'),
                ],
            ];
        }

        // create new file
        Storage::disk($disk)->put($path, '');

        // get file properties
        $fileProperties = $this->fileProperties($disk, $path);

        return [
            'result' => [
                'status'  => 'success',
                'message' => trans('file-manager::response.fileCreated'),
            ],
            'file'   => $fileProperties,
        ];
    }

    /**
     * Update file
     *
     * @param $disk
     * @param $path
     * @param $file
     *
     * @return array
     */
    public function updateFile($disk, $path, $file)
    {
        // update file
        Storage::disk($disk)->putFileAs(
            $path,
            $file,
            $file->getClientOriginalName()
        );

        // path for new file
        $filePath = $this->newPath($path, $file->getClientOriginalName());

        // get file properties
        $fileProperties = $this->fileProperties($disk, $filePath);

        return [
            'result' => [
                'status'  => 'success',
                'message' => trans('file-manager::response.fileUpdated'),
            ],
            'file'   => $fileProperties,
        ];
    }

    /**
     * Stream file - for audio and video
     *
     * @param $disk
     * @param $path
     *
     * @return mixed
     */
    public function streamFile($disk, $path)
    {
        // if file name not in ASCII format
        if (!preg_match('/^[\x20-\x7e]*$/', basename($path))) {
            $filename = Str::ascii(basename($path));
        } else {
            $filename = basename($path);
        }

        return Storage::disk($disk)
            ->response($path, $filename, ['Accept-Ranges' => 'bytes']);
    }
}

