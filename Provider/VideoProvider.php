<?php

namespace Xmon\SonataMediaProviderVideoBundle\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Sonata\MediaBundle\Provider\MediaProviderInterface;
use Sonata\MediaBundle\Provider\MetadataInterface;
use Symfony\Component\Filesystem\Filesystem as Fs;
use Sonata\MediaBundle\Model\MediaInterface;
use Sonata\MediaBundle\Resizer\ResizerInterface;
use Sonata\MediaBundle\Provider\Metadata;
use Sonata\MediaBundle\CDN\CDNInterface;
use Sonata\MediaBundle\Generator\GeneratorInterface;
use Sonata\MediaBundle\Thumbnail\ThumbnailInterface;
use Sonata\MediaBundle\Metadata\MetadataBuilderInterface;
use Sonata\AdminBundle\Form\FormMapper;
use Symfony\Component\Form\Event\PostSetDataEvent;
use Symfony\Component\Form\Event\PreSetDataEvent;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Gaufrette\Filesystem;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Format\Video;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;

class VideoProvider extends BaseFileProvider
{
    protected array $allowedExtensions;
    protected array $allowedMimeTypes;
    protected ?MetadataBuilderInterface $metadata;
    protected FFProbe $ffprobe;
    protected FFMpeg $ffmpeg;
    protected ContainerInterface $container;
    protected int $configImageFrame;
    protected int $configVideoWidth;
    protected bool $configMp4;
    protected bool $configOgg;
    protected bool $configWebm;
    protected EntityManagerInterface $entityManager;
    protected ThumbnailInterface $thumbnail;

    protected string $ext = 'jpg';
    protected FormMapper $formMapper;

    public function __construct(
        string $name,
        Filesystem $filesystem,
        CDNInterface $cdn,
        GeneratorInterface $pathGenerator,
        ThumbnailInterface $thumbnail,
        array $allowedExtensions,
        array $allowedMimeTypes,
        ResizerInterface $resizer,
        MetadataBuilderInterface $metadata,
        ContainerInterface $container,
        EntityManagerInterface $entityManager
    ) {

        parent::__construct($name, $filesystem, $cdn, $pathGenerator, $thumbnail, $allowedExtensions, $allowedMimeTypes,
            $metadata);

        $this->container = $container;

        $this->allowedExtensions = $allowedExtensions;
        $this->allowedMimeTypes = $allowedMimeTypes;
        $this->metadata = $metadata;
        $this->resizer = $resizer;
        $this->ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => $this->container->getParameter('xmon_ffmpeg.binary'),
            'ffprobe.binaries' => $this->container->getParameter('xmon_ffprobe.binary'),
            'timeout' => $this->container->getParameter('xmon_ffmpeg.binary_timeout'),
            'ffmpeg.threads' => $this->container->getParameter('xmon_ffmpeg.threads_count')
        ]);
        $this->ffprobe = FFProbe::create([
            'ffmpeg.binaries' => $this->container->getParameter('xmon_ffmpeg.binary'),
            'ffprobe.binaries' => $this->container->getParameter('xmon_ffprobe.binary')
        ]);
        $this->container = $container;
        $this->entityManager = $entityManager;
        $this->thumbnail = $thumbnail;

        // configuración
        $this->configImageFrame = $this->container->getParameter('xmon_ffmpeg.image_frame');
        $this->configVideoWidth = $this->container->getParameter('xmon_ffmpeg.video_width');
        $this->configMp4 = $this->container->getParameter('xmon_ffmpeg.mp4');
        $this->configOgg = $this->container->getParameter('xmon_ffmpeg.ogg');
        $this->configWebm = $this->container->getParameter('xmon_ffmpeg.webm');
    }

    public function buildCreateForm(FormMapper $formMapper): void
    {
        $this->formMapper = $formMapper;
        $formMapper->getFormBuilder()->addEventListener(
            FormEvents::POST_SUBMIT, [$this, 'setFormOptions']
        );
        $formMapper->add('binaryContent', FileType::class, array(
            'constraints' => array(
                new NotBlank(),
                new NotNull(),
            )
        ));

        $formMapper->add('thumbnailCapture', NumberType::class, array(
            'mapped' => false,
            'required' => false,
            'label' => 'Thumbnail generator (set value in seconds)',
        ))
            ->add('autoplay', CheckboxType::class, array(
                    'mapped' => false,
                    'required' => false,
                )
            )
            ->add('loop', CheckboxType::class, array(
                    'mapped' => false,
                    'required' => false,
                )
            )
            ->add('muted', CheckboxType::class, array(
                    'mapped' => false,
                    'required' => false,
                )
            )
            ->add('controls', CheckboxType::class, array(
                    'mapped' => false,
                    'required' => false,
                    'data' => true,
                )
            );
    }

    public function buildEditForm(FormMapper $formMapper): void
    {
        $this->formMapper = $formMapper;
        parent::buildEditForm($formMapper);
        $formMapper->getFormBuilder()->addEventListener(
            FormEvents::POST_SUBMIT, [$this, 'setFormOptions']
        );
        $formMapper->getFormBuilder()->addEventListener(
            FormEvents::POST_SET_DATA, [$this, 'getFormOptions']
        );

        $formMapper->add('thumbnailCapture', NumberType::class, array(
            'mapped' => false,
            'required' => false,
            'label' => 'Thumbnail generator (set value in seconds)',
        ))
            ->add('autoplay', CheckboxType::class, array(
                    'mapped' => false,
                    'required' => false,
                )
            )
            ->add('loop', CheckboxType::class, array(
                    'mapped' => false,
                    'required' => false,
                )
            )
            ->add('muted', CheckboxType::class, array(
                    'mapped' => false,
                    'required' => false,
                )
            )
            ->add('controls', CheckboxType::class, array(
                    'mapped' => false,
                    'required' => false,
                )
            );
    }

    protected function doTransform(MediaInterface $media): void
    {
        parent::doTransform($media);

        if (!is_object($media->getBinaryContent()) && !$media->getBinaryContent()) {
            return;
        }

        $stream = $this->ffprobe
            ->streams($media->getBinaryContent()->getRealPath())
            ->videos()
            ->first();

        $duration = $stream->get('duration');
        $height = $stream->get('height');
        $width = $stream->get('width');

        if ($media->getBinaryContent()) {
            $media->setContentType($media->getBinaryContent()->getMimeType());
            $media->setSize($media->getBinaryContent()->getSize());
            $media->setWidth($width);
            $media->setHeight($height);
            $media->setLength($duration);
        }

        $media->setProviderName($this->name);
        $media->setProviderStatus(MediaInterface::STATUS_OK);
    }

    protected function fixBinaryContent(MediaInterface $media): void
    {
        if ($media->getBinaryContent() === null) {
            return;
        }

        // if the binary content is a filename => convert to a valid File
        if (!$media->getBinaryContent() instanceof File) {
            if (!is_file($media->getBinaryContent())) {
                throw new \RuntimeException('The file does not exist : ' . $media->getBinaryContent());
            }

            $binaryContent = new File($media->getBinaryContent());

            $media->setBinaryContent($binaryContent);
        }
    }

    protected function generateReferenceName(MediaInterface $media): string
    {
        return sha1($media->getName() . rand(11111, 99999)) . '.' . $media->getBinaryContent()->guessExtension();
    }

    public function getProviderMetadata(): MetadataInterface
    {
        return new Metadata($this->getName(), $this->getName() . '.description', false, 'SonataMediaBundle',
            array('class' => 'fa fa fa-video-camera'));
    }

    public function generateThumbnails(MediaInterface $media): void
    {
        $this->generateReferenceImage($media);

        if (!$this->requireThumbnails()) {
            return;
        }

        $referenceImage = $this->getFilesystem()->get($this->getReferenceImage($media));

        if (!$referenceImage->exists()) {
            return;
        }

        foreach ($this->getFormats() as $format => $settings) {
            if (str_starts_with($format, $media->getContext()) || $format === MediaProviderInterface::FORMAT_ADMIN) {
                $resizer = $this->getResizer();

                if (null === $resizer) {
                    continue;
                }
                $path = $this->generatePrivateUrl($media, $format);
                $resizer->resize(
                    $media,
                    $referenceImage,
                    $this->getFilesystem()->get($path, true),
                    $settings['format'] ?? $this->ext,
                    $settings
                );
            }
        }
    }

    public function generateVideos(MediaInterface $media): void
    {

        // obtengo la ruta del archivo original
        $source = sprintf('%s/%s/%s', $this->getFilesystem()->getAdapter()->getDirectory(), $this->generatePath($media),
            $media->getProviderReference());

        // determino las dimensiones del vídeo
        $height = round($this->configVideoWidth * $media->getHeight() / $media->getWidth());

        // corrección para que el alto no sea impar, si es impar PETA ffmpeg
        if ($height % 2 != 0) {
            $height = $height - 1;
        }

        /** @var \FFMpeg\Media\Video $video */
        $video = $this->ffmpeg->open($source);
        $video
            ->filters()
            ->resize(new Dimension($this->configVideoWidth, $height))
            ->synchronize();

        if ($this->configMp4) {
            // genero los nombres de archivos de cada uno de los formatos
            $pathMp4 = sprintf('%s/%s/videos_mp4_%s', $this->getFilesystem()->getAdapter()->getDirectory(),
                $this->generatePath($media), $media->getId() . '.mp4');
            $mp4 = preg_replace('/\.[^.]+$/', '.' . 'mp4', $pathMp4);
            $video->save(new Video\X264('libmp3lame'), $mp4);
            $media->setProviderMetadata(['filename_mp4' => $mp4]);
        }

        if ($this->configOgg) {
            $pathOgg = sprintf('%s/%s/videos_ogg_%s', $this->getFilesystem()->getAdapter()->getDirectory(),
                $this->generatePath($media), $media->getId() . '.ogg');
            $ogg = preg_replace('/\.[^.]+$/', '.' . 'ogg', $pathOgg);
            $video->save(new Video\Ogg(), $ogg);
        }

        if ($this->configWebm) {
            $pathWebm = sprintf('%s/%s/videos_webm_%s', $this->getFilesystem()->getAdapter()->getDirectory(),
                $this->generatePath($media), $media->getId() . '.webm');
            $webm = preg_replace('/\.[^.]+$/', '.' . 'webm', $pathWebm);
            $video->save(new Video\WebM(), $webm);
        }

        //If no conversion format available simply duplicate file with the right name
        if (!$this->configMp4 && !$this->configOgg && !$this->configOgg) {
            $filename = sprintf('videos_mp4_%s', $media->getId() . '.mp4');
            $path = sprintf('%s/%s/', $this->getFilesystem()->getAdapter()->getDirectory(),
                $this->generatePath($media));
            $fs = new Fs();
            $fs->copy($path . '/' . $media->getProviderReference(), $path . '/' . $filename, true);
        }
    }

    public function generateThumbsPrivateUrl($media, $format, $ext = 'jpg'): string
    {
        return sprintf('%s/thumb_%s_%s.%s', $this->generatePath($media), $media->getId(), $format, $ext);
    }

    public function generatePrivateUrl(MediaInterface $media, $format): string
    {
        if (MediaProviderInterface::FORMAT_REFERENCE === $format) {
            return $this->getReferenceImage($media);
        }

        $id = $media->getId();

        if (null === $id) {
            throw new \InvalidArgumentException('Unable to generate private url for image without id.');
        }

        return sprintf('%s/thumb_%s_%s.%s', $this->generatePath($media), $id, $format, $this->ext);
    }

    public function generatePublicUrl(MediaInterface $media, $format): string
    {
        $path = $this->generateUrl($media, $format);

        return $this->getCdn()->getPath($path, $media->getCdnIsFlushable());
    }

    private function generateUrl(MediaInterface $media, $format): string
    {
        if ($format == 'reference') {
            return sprintf('%s/%s', $this->generatePath($media), $media->getProviderReference());
        }
        if ($format == 'admin') {
            return sprintf('%s/%s', $this->generatePath($media),
                str_replace($this->getExtension($media), $this->ext, $media->getProviderReference()));
        }
        if ($format == 'thumb_admin') {
            return sprintf('%s/thumb_%d_%s.' . $this->ext, $this->generatePath($media), $media->getId(), 'admin');
        }
        if ($format == 'videos_ogg') {
            return sprintf('%s/%s_%s', $this->generatePath($media), $format,
                str_replace($media->getExtension(), 'ogg', $media->getId() . '.ogg'));
        }
        if ($format == 'videos_webm') {
            return sprintf('%s/%s_%s', $this->generatePath($media), $format,
                str_replace($media->getExtension(), 'webm', $media->getId() . '.webm'));
        }
        if ($format == 'videos_mp4') {
            return sprintf('%s/%s_%s', $this->generatePath($media), $format,
                str_replace($media->getExtension(), 'mp4', $media->getId() . '.mp4'));
        }

        return $this->generatePrivateUrl($media, $format);
    }

    public function getHelperProperties(MediaInterface $media, $format, $options = array()): array
    {
        if ($format == 'reference') {
            $box = $media->getBox();
        } else {
            $resizerFormat = $this->getFormat($format);
            if ($resizerFormat === false) {
                throw new \RuntimeException(sprintf('The image format "%s" is not defined.
                        Is the format registered in your ``sonata_media`` configuration?', $format));
            }

            $box = $this->resizer->getBox($media, $resizerFormat);
        }

        return array_merge($media->getMetadataValue('options', []), array(
            'id' => key_exists("id", $options) ? $options["id"] : $media->getId(),
            'alt' => $media->getName(),
            'title' => $media->getName(),
            'thumbnail' => $this->getReferenceImage($media),
            'src' => $this->generatePublicUrl($media, $format),
            'file' => $this->generatePublicUrl($media, $format),
            'realref' => $media->getProviderReference(),
            'width' => $box->getWidth(),
            'height' => $box->getHeight(),
            'duration' => $media->getLength(),
            'video_mp4' => $this->generatePublicUrl($media, "videos_mp4"),
            'video_ogg' => $this->generatePublicUrl($media, "videos_ogg"),
            'video_webm' => $this->generatePublicUrl($media, "videos_webm")
        ), $options);
    }

    public function getReferenceImage(MediaInterface $media): string
    {
        return sprintf('%s/%s', $this->generatePath($media),
            str_replace($this->getExtension($media), $this->ext, $media->getProviderReference()));
    }

    public function generateReferenceImage(MediaInterface $media): void
    {

        $path = sprintf(
            '%s/%s/%s', $this->getFilesystem()->getAdapter()->getDirectory(), $this->generatePath($media),
            $media->getProviderReference()
        );

        $stream = $this->ffprobe
            ->streams($path)
            ->videos()
            ->first();

        $video = $this->ffmpeg->open($path);

        if (!$media->getProviderReference()) {
            $media->setProviderReference($this->generateReferenceName($media));
        }

        // recojo el punto de extracción de la imagen definido en la configuración
        $secondsExtract = (int)$this->formMapper->getAdmin()->getForm()->get('thumbnailCapture')->getData()
            ?: $this->configImageFrame;
        // conocemos la duración del vídeo
        $duration = $stream->get('duration');

        // compruebo que el punto de extracción está dentro de la duración del video
        // si no está dentro, entonces calculo la mitad de la duración
        if ($secondsExtract > $duration) {
            $secondsExtract = $duration / 2;
        }

        $timecode = TimeCode::fromSeconds($secondsExtract);
        $frame = $video->frame($timecode);

        if (!$frame) {
            echo "Thumbnail Generation Failed";
            exit;
        }

        $thumnailPath = sprintf(
            '%s/%s/%s',
            $this->getFilesystem()->getAdapter()->getDirectory(),
            $this->generatePath($media),
            str_replace(
                $this->getExtension($media), $this->ext, $media->getProviderReference()
            )
        );

        $frame->save($thumnailPath);
    }

    private function setProviderMetadataAvailableVideoFormat(MediaInterface $media): void
    {
        $this->configImageFrame = 10;
        $metadata = $media->getProviderMetadata('filename');

        // genero los nombres de archivos de cada uno de los formatos
        if ($this->configMp4) {
            $metadata['mp4_available'] = true;
        }
        if ($this->configOgg) {
            $metadata['ogg_available'] = true;
        }
        if ($this->configWebm) {
            $metadata['webm_available'] = true;
        }
        if (!$this->configMp4 && !$this->configOgg && !$this->configOgg) {
            $metadata['mp4_available'] = true;
        }

        $media->setProviderMetadata($metadata);
    }

    private function getAvailableFormatToUpdateOrDelete(): void
    {
        if ($this->configMp4) {
            $this->addFormat('videos_mp4', ['mp4']);
        }
        if ($this->configOgg) {
            $this->addFormat('videos_ogg', ['ogg']);
        }
        if ($this->configWebm) {
            $this->addFormat('videos_webm', ['webm']);
        }
        if (!$this->configMp4 && !$this->configOgg && !$this->configOgg) {
            $this->addFormat('videos_mp4', ['mp4']);
        }
        $this->addFormat('reference', ['reference']);
        $this->addFormat('thumb_admin', ['thumb_admin']);
    }

    public function prePersist(MediaInterface $media): void
    {
        if (!$media->getBinaryContent()) {
            return;
        }
        $this->setProviderMetadataAvailableVideoFormat($media);
    }

    public function postPersist(MediaInterface $media): void
    {
        if (!$media->getBinaryContent()) {
            return;
        }

        $this->setFileContents($media);

        $this->generateThumbnails($media);

        $this->generateVideos($media);
    }

    public function preRemove(MediaInterface $media): void
    {

        // arreglo para eliminar la relación del video con la galería
        if ($galleryItems = $media->getGalleryItems()) {
            foreach ($galleryItems as $galleryItem) {
                $this->entityManager->remove($galleryItem);
            }
        }

        $this->getAvailableFormatToUpdateOrDelete();

        $path = $this->getReferenceImage($media);

        if ($this->getFilesystem()->has($path)) {
            $this->getFilesystem()->delete($path);
        }

        if ($this->requireThumbnails()) {
            $this->thumbnail->delete($this, $media);
        }
    }

    public function preUpdate(MediaInterface $media): void
    {
        if (!$media->getBinaryContent()) {
            return;
        }

        $this->setProviderMetadataAvailableVideoFormat($media);
    }

    public function postUpdate(MediaInterface $media): void
    {
        if (!$media->getBinaryContent() instanceof \SplFileInfo) {
            return;
        }

        // Delete the current file from the FS
        $oldMedia = clone $media;
        $oldMedia->setProviderReference($media->getPreviousProviderReference());

        $this->getAvailableFormatToUpdateOrDelete();

        if ($this->getFilesystem()->has($oldMedia)) {
            $this->getFilesystem()->delete($oldMedia);
        }

        if ($this->requireThumbnails()) {
            $this->thumbnail->delete($this, $oldMedia);
        }

        $this->fixBinaryContent($media);

        $this->setFileContents($media);

        $this->generateThumbnails($media);

        $this->generateVideos($media);
    }

    public function updateMetadata(MediaInterface $media, $force = false): void
    {
        $file = sprintf('%s/%s/%s', $this->getFilesystem()->getAdapter()->getDirectory(), $this->generatePath($media),
            $media->getProviderReference());

        $stream = $this->ffprobe
            ->streams($file)
            ->videos()
            ->first();

        $media->setContentType(mime_content_type($file));
        $media->setSize(filesize($file));

        $media->setWidth($stream->get('width'));
        $media->setHeight($stream->get('height'));
        $media->setLength($this->ffprobe->format($file)->get('duration'));

        $media->setMetadataValue('bitrate', $stream->get('bit_rate'));
    }

    public function setFormOptions(FormEvent $dataEvent): void
    {
        $form = $dataEvent->getForm();
        $media = $form->getData();
        $media->setMetadataValue('options', [
            'autoplay' => $form->get('autoplay')->getData(),
            'loop' => $form->get('loop')->getData(),
            'muted' => $form->get('muted')->getData(),
            'controls' => $form->get('controls')->getData(),
        ]);
    }

    public function getFormOptions(FormEvent $dataEvent): void
    {
        $form = $dataEvent->getForm();
        $media = $form->getData();
        if (!$media) {
            return;
        }
        $options = $media->getMetadataValue('options');
        $form->get('autoplay')->setData($options['autoplay'] ?? false);
        $form->get('loop')->setData($options['loop'] ?? false);
        $form->get('muted')->setData($options['muted'] ?? false);
        $form->get('controls')->setData($options['controls'] ?? false);
    }

    /**
     * @return string the file extension for the $media, or the $defaultExtension if not available
     */
    protected function getExtension(MediaInterface $media): string
    {
        $ext = $media->getExtension();
        if (!is_string($ext) || strlen($ext) < 2) {
            $ext = "mp4";
        }
        return $ext;
    }
}
