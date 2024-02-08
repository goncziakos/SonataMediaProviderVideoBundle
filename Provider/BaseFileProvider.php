<?php

namespace Xmon\SonataMediaProviderVideoBundle\Provider;

use Gaufrette\File as GaufretteFile;
use Gaufrette\Filesystem;
use Sonata\AdminBundle\Form\FormMapper;
use Sonata\Form\Validator\ErrorElement;
use Sonata\MediaBundle\CDN\CDNInterface;
use Sonata\MediaBundle\Filesystem\Local;
use Sonata\MediaBundle\Generator\GeneratorInterface;
use Sonata\MediaBundle\Model\MediaInterface;
use Sonata\MediaBundle\Provider\FileProviderInterface;
use Sonata\MediaBundle\Provider\MediaProviderInterface;
use Sonata\MediaBundle\Provider\Metadata;
use Sonata\MediaBundle\Provider\MetadataInterface;
use Sonata\MediaBundle\Resizer\ResizerInterface;
use Sonata\MediaBundle\Thumbnail\GenerableThumbnailInterface;
use Sonata\MediaBundle\Thumbnail\ThumbnailInterface;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\UploadException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class BaseFileProvider implements FileProviderInterface
{
    /**
     * @var array<string, array<string, int|string|bool|array|null>>
     *
     * @phpstan-var array<string, FormatOptions>
     */
    protected array $formats = [];

    /**
     * @var string[]
     */
    protected array $templates = [];

    protected ?ResizerInterface $resizer = null;

    /**
     * @var MediaInterface[]
     */
    private array $clones = [];

    public function __construct(
        protected string $name,
        protected Filesystem $filesystem,
        protected CDNInterface $cdn,
        protected GeneratorInterface $pathGenerator,
        protected ThumbnailInterface $thumbnail
    ) {
    }

    public function transform(MediaInterface $media): void
    {
        if (null === $media->getBinaryContent()) {
            return;
        }

        $this->doTransform($media);
        $this->flushCdn($media);
    }

    public function flushCdn(MediaInterface $media): void
    {
        if (null === $media->getId() || !$media->getCdnIsFlushable()) {
            // If the medium is new or if it isn't marked as flushable, skip the CDN flush process.
            return;
        }

        $flushIdentifier = $media->getCdnFlushIdentifier();

        // Check if the medium already has a pending CDN flush.
        if (null !== $flushIdentifier) {
            $cdnStatus = $this->getCdn()->getFlushStatus($flushIdentifier);
            // Update the flush status.
            $media->setCdnStatus($cdnStatus);

            if (!\in_array($cdnStatus, [CDNInterface::STATUS_OK, CDNInterface::STATUS_ERROR], true)) {
                // If the previous flush process is still pending, do nothing.
                return;
            }

            // If the previous flush process is finished, we clean its identifier.
            $media->setCdnFlushIdentifier(null);

            if (CDNInterface::STATUS_OK === $cdnStatus) {
                $media->setCdnFlushAt(new \DateTime());
            }
        }

        $flushPaths = [];

        foreach ($this->getFormats() as $format => $settings) {
            if (
                MediaProviderInterface::FORMAT_ADMIN === $format
                || substr($format, 0, \strlen($media->getContext() ?? '')) === $media->getContext()
            ) {
                $flushPaths[] = $this->getFilesystem()->get($this->generatePrivateUrl($media, $format), true)->getKey();
            }
        }

        if ([] !== $flushPaths) {
            $cdnFlushIdentifier = $this->getCdn()->flushPaths($flushPaths);
            $media->setCdnFlushIdentifier($cdnFlushIdentifier);
            $media->setCdnStatus(CDNInterface::STATUS_TO_FLUSH);
        }
    }

    public function addFormat(string $name, array $settings): void
    {
        $this->formats[$name] = $settings;
    }

    public function getFormat(string $name)
    {
        return $this->formats[$name] ?? false;
    }

    public function requireThumbnails(): bool
    {
        return null !== $this->getResizer();
    }

    public function generateThumbnails(MediaInterface $media): void
    {
        if ($this->thumbnail instanceof GenerableThumbnailInterface) {
            $this->thumbnail->generate($this, $media);
        }
    }

    public function removeThumbnails(MediaInterface $media, $formats = null): void
    {
        if ($this->thumbnail instanceof GenerableThumbnailInterface) {
            $this->thumbnail->delete($this, $media, $formats);
        }
    }

    public function getFormatName(MediaInterface $media, string $format): string
    {
        if (MediaProviderInterface::FORMAT_ADMIN === $format) {
            return MediaProviderInterface::FORMAT_ADMIN;
        }

        if (MediaProviderInterface::FORMAT_REFERENCE === $format) {
            return MediaProviderInterface::FORMAT_REFERENCE;
        }

        $baseName = $media->getContext().'_';
        if (str_starts_with($format, $baseName)) {
            return $format;
        }

        return $baseName.$format;
    }

    public function getProviderMetadata(): MetadataInterface
    {
        return new Metadata($this->getName(), $this->getName().'.description', null, 'SonataMediaBundle', ['class' => 'fa fa-file']);
    }

    public function preRemove(MediaInterface $media): void
    {
        $hash = spl_object_hash($media);
        $this->clones[$hash] = clone $media;

        if ($this->requireThumbnails()) {
            $this->removeThumbnails($media);
        }
    }

    public function postRemove(MediaInterface $media): void
    {
        $hash = spl_object_hash($media);

        if (isset($this->clones[$hash])) {
            $media = $this->clones[$hash];
            unset($this->clones[$hash]);
        }

        $path = $this->getReferenceImage($media);

        if ($this->getFilesystem()->has($path)) {
            $this->getFilesystem()->delete($path);
        }
    }

    public function generatePath(MediaInterface $media): string
    {
        return $this->pathGenerator->generatePath($media);
    }

    public function getFormats(): array
    {
        return $this->formats;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setTemplates(array $templates): void
    {
        $this->templates = $templates;
    }

    public function getTemplates(): array
    {
        return $this->templates;
    }

    public function getTemplate(string $name): ?string
    {
        return $this->templates[$name] ?? null;
    }

    public function getResizer(): ?ResizerInterface
    {
        return $this->resizer;
    }

    public function getFilesystem(): Filesystem
    {
        return $this->filesystem;
    }

    public function getCdn(): CDNInterface
    {
        return $this->cdn;
    }

    public function getCdnPath(string $relativePath, bool $isFlushable = false): string
    {
        return $this->getCdn()->getPath($relativePath, $isFlushable);
    }

    public function setResizer(ResizerInterface $resizer): void
    {
        $this->resizer = $resizer;
    }

    public function prePersist(MediaInterface $media): void
    {
        $media->setCreatedAt(new \DateTime());
        $media->setUpdatedAt(new \DateTime());
    }

    public function preUpdate(MediaInterface $media): void
    {
        $media->setUpdatedAt(new \DateTime());
    }

    public function validate(ErrorElement $errorElement, MediaInterface $media): void
    {
    }

    public function buildEditForm(FormMapper $form): void
    {
        $form->add('name');
        $form->add('enabled', null, ['required' => false]);
        $form->add('authorName');
        $form->add('cdnIsFlushable');
        $form->add('description');
        $form->add('copyright');
        $form->add('binaryContent', FileType::class, ['required' => false]);
    }

    public function getAllowedExtensions(): array
    {
        return $this->allowedExtensions;
    }

    public function getAllowedMimeTypes(): array
    {
        return $this->allowedMimeTypes;
    }

    public function getReferenceFile(MediaInterface $media): GaufretteFile
    {
        return $this->getFilesystem()->get($this->getReferenceImage($media), true);
    }

    public function getDownloadResponse(MediaInterface $media, string $format, string $mode, array $headers = []): Response
    {
        // build the default headers
        $headers = array_merge([
            'Content-Type' => $media->getContentType(),
            'Content-Disposition' => sprintf('attachment; filename="%s"', $media->getMetadataValue('filename')),
        ], $headers);

        if (!\in_array($mode, ['http', 'X-Sendfile', 'X-Accel-Redirect'], true)) {
            throw new \RuntimeException('Invalid mode provided');
        }

        if ('http' === $mode) {
            if (MediaProviderInterface::FORMAT_REFERENCE === $format) {
                $file = $this->getReferenceFile($media);
            } else {
                $file = $this->getFilesystem()->get($this->generatePrivateUrl($media, $format));
            }

            return new StreamedResponse(static function () use ($file): void {
                echo $file->getContent();
            }, 200, $headers);
        }

        $adapter = $this->getFilesystem()->getAdapter();

        if (!$adapter instanceof Local) {
            throw new \RuntimeException(sprintf('Cannot use X-Sendfile or X-Accel-Redirect with non %s.', Local::class));
        }

        $directory = $adapter->getDirectory();

        if (false === $directory) {
            throw new \RuntimeException('Cannot retrieve directory from the adapter.');
        }

        return new BinaryFileResponse(
            sprintf('%s/%s', $directory, $this->generatePrivateUrl($media, $format)),
            200,
            $headers
        );
    }

    public function buildMediaType(FormBuilderInterface $formBuilder): void
    {
        $formBuilder->add('binaryContent', FileType::class, [
            'required' => false,
            'label' => 'widget_label_binary_content',
        ]);
    }

    /**
     * Set the file contents for an image.
     */
    protected function setFileContents(MediaInterface $media, ?string $contents = null): void
    {
        $providerReference = $media->getProviderReference();

        if (null === $providerReference) {
            throw new \RuntimeException(sprintf(
                'Unable to generate path to file without provider reference for media "%s".',
                (string) $media
            ));
        }

        $file = $this->getFilesystem()->get(
            sprintf('%s/%s', $this->generatePath($media), $providerReference),
            true
        );

        $metadata = null !== $this->metadata ? $this->metadata->get($media, $file->getName()) : [];

        if (null !== $contents) {
            $file->setContent($contents, $metadata);

            return;
        }

        $binaryContent = $media->getBinaryContent();
        if ($binaryContent instanceof File) {
            $path = false !== $binaryContent->getRealPath() ? $binaryContent->getRealPath() : $binaryContent->getPathname();
            $fileContents = file_get_contents($path);

            if (false === $fileContents) {
                throw new \RuntimeException(sprintf('Unable to get file contents for media %s', $media->getId() ?? ''));
            }

            $file->setContent($fileContents, $metadata);

            return;
        }
    }

    /**
     * @throws \RuntimeException
     */
    protected function fixFilename(MediaInterface $media): void
    {
        if ($media->getBinaryContent() instanceof UploadedFile) {
            $media->setName($media->getName() ?? $media->getBinaryContent()->getClientOriginalName());
            $media->setMetadataValue('filename', $media->getBinaryContent()->getClientOriginalName());
        } elseif ($media->getBinaryContent() instanceof File) {
            $media->setName($media->getName() ?? $media->getBinaryContent()->getBasename());
            $media->setMetadataValue('filename', $media->getBinaryContent()->getBasename());
        }

        // This is the original name
        if (null === $media->getName()) {
            throw new \RuntimeException('Please define a valid media\'s name');
        }
    }

    protected function doTransform(MediaInterface $media): void
    {
        $this->fixBinaryContent($media);
        $this->fixFilename($media);

        if ($media->getBinaryContent() instanceof UploadedFile && 0 === $media->getBinaryContent()->getSize()) {
            $media->setProviderReference(uniqid($media->getName() ?? '', true));
            $media->setProviderStatus(MediaInterface::STATUS_ERROR);

            throw new UploadException('The uploaded file is not found');
        }

        // this is the name used to store the file
        if (null === $media->getProviderReference()
            || MediaInterface::MISSING_BINARY_REFERENCE === $media->getProviderReference()
        ) {
            $media->setProviderReference($this->generateReferenceName($media));
        }

        if ($media->getBinaryContent() instanceof File) {
            $media->setContentType($media->getBinaryContent()->getMimeType());
            $media->setSize($media->getBinaryContent()->getSize());
        }

        $media->setProviderStatus(MediaInterface::STATUS_OK);
    }
}
