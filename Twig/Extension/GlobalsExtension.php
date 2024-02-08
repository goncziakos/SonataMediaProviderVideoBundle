<?php

/*
 * aqui defino todas las variables globales
 * para poder recoger en cualquier plantilla twig del bundle
 */

namespace Xmon\SonataMediaProviderVideoBundle\Twig\Extension;

use Sonata\MediaBundle\Provider\Pool;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;

/**
 * Description of GlobalsExtension
 *
 * @author Juanjo García <juanjogarcia@editartgroup.com>
 */

class GlobalsExtension extends AbstractExtension implements GlobalsInterface {

    public function getFilters(): array
    {
        return array(
            new TwigFilter('video_mp4', array($this, 'videoFormatMp4')),
            new TwigFilter('video_ogg', array($this, 'videoFormatOgg')),
            new TwigFilter('video_webm', array($this, 'videoFormatWebm'))
        );
    }

    public function __construct(protected int $width, protected Pool $mediaService) {
    }

    public function videoFormatMp4($media): string
    {
        $provider = $this
            ->getMediaService()
            ->getProvider($media->getProviderName());

        return $provider->generatePublicUrl($media, "videos_mp4");
    }

    public function videoFormatOgg($media): string
    {
        $provider = $this
            ->getMediaService()
            ->getProvider($media->getProviderName());

        return $provider->generatePublicUrl($media, "videos_ogg");
    }

    public function videoFormatWebm($media): string
    {
        $provider = $this
            ->getMediaService()
            ->getProvider($media->getProviderName());

        return $provider->generatePublicUrl($media, "videos_webm");
    }

    public function getGlobals(): array
    {
        return array(
            'width' => $this->width
        );
    }

    public function getMediaService(): Pool
    {
        return $this->mediaService;
    }

    public function getName(): string
    {
        return 'SonataMediaProviderVideoBundle:GlobalsExtension';
    }

}
