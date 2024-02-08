SonataMediaProviderVideoBundle
==============================

The ``SonataMediaProviderVideoBundle`` extends providers [SonataMediaBundle](https://github.com/sonata-project/SonataMediaBundle), 
creates a new video ``provider`` for uploading videos, generate thumbnail and use FFmpeg.

This Bundle is based on [xmon/SonataMediaProviderVideoBundle](https://github.com/xmon/SonataMediaProviderVideoBundle),
forked from Grand-Central/SonataMediaProviderVideoBundle [Grand-Central/SonataMediaProviderVideoBundle](https://github.com/Grand-Central/SonataMediaProviderVideoBundle) 
appear to be abandoned and I have made many changes, so I decided to 
create a new functional and documented project.

## Requirements

You need install [ffmpeg](https://www.ffmpeg.org/) in your server.

## Installation

### Install this bundle
```sh
$ composer require xmon/sonata-media-provider-video-bundle 
```

## Add VideoBundle to your application kernel
```php
// config/bundles.php
<?php

return [
    // ...
    Xmon\SonataMediaProviderVideoBundle\XmonSonataMediaProviderVideoBundle::class => ['all' => true],
];
```

## Configuration example

fter installing the bundle, make sure you configure these parameters

```yaml
xmon_sonata_media_provider_video:
    ffmpeg_binary: "/usr/bin/ffmpeg" # Required, ffmpeg binary path
    ffprobe_binary: "/usr/bin/ffprobe" # Required, ffprobe binary path
    binary_timeout: 60 # Optional, default 60
    threads_count: 4 # Optional, default 4
    config:
        image_frame: 0 # Optional, default 10, Can not be empty. Where the second image capture
        video_width: 640 # Optional, default 640, Can not be empty. Video proportionally scaled to this width
    formats:
        mp4: true # Optional, default true, generate MP4 format
        ogg: true # Optional, default true, generate OGG format
        webm: true # Optional, default true, generate WEBM format
```
## Twig usage

For printing the URLs of the converted videos that have been saved in the metadata field, I have created 3 twig filters

```twig
{{ media|video_mp4 }}
{{ media|video_ogg }}
{{ media|video_webm }}
```

### Credits

 - Thanks to all contributors who participated in the initial Forks of this project. Especially with the main Fork [(maerianne/MaesboxVideoBundle)](https://github.com/maerianne/MaesboxVideoBundle) and Fork [(sergeym/VideoBundle)](https://github.com/sergeym/VideoBundle) I used to continue my development.
 - Thanks other proyects required by this one:
	 - [SonataMediaBundle](https://github.com/sonata-project/SonataMediaBundle).
	 - [GetId3](https://github.com/phansys/GetId3)
	 - [PHP FFmpeg](https://github.com/PHP-FFMpeg/PHP-FFMpeg)
 - It has been used [videojs](http://videojs.com/) plugin such as video player in the administration
