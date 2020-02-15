<?php

namespace Phpactor\WorkspaceQuery\Adapter\Worse;

use DTL\Invoke\Invoke;
use Generator;
use Phpactor\Name\FullyQualifiedName;
use Phpactor\WorkspaceQuery\Model\FileList;
use Phpactor\WorkspaceQuery\Model\Index;
use Phpactor\WorkspaceQuery\Model\IndexBuilder;
use Phpactor\WorkspaceQuery\Model\Record\ClassRecord;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\WorseReflection\Core\Exception\SourceNotFound;
use Phpactor\WorseReflection\Core\Reflection\Collection\ReflectionClassCollection;
use Phpactor\WorseReflection\Core\Reflection\Collection\ReflectionCollection;
use Phpactor\WorseReflection\Core\Reflection\ReflectionClass;
use Phpactor\WorseReflection\Core\Reflection\ReflectionClassLike;
use Phpactor\WorseReflection\Core\Reflection\ReflectionInterface;
use Phpactor\WorseReflection\Core\Reflector\SourceCodeReflector;
use Phpactor\WorseReflection\Core\SourceCode;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SplFileInfo;
use function Safe\file_get_contents;

class WorseIndexBuilder implements IndexBuilder
{
    /**
     * @var Index
     */
    private $index;

    /**
     * @var SourceCodeReflector
     */
    private $reflector;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        Index $index,
        SourceCodeReflector $reflector,
        LoggerInterface $logger
    ) {
        $this->index = $index;
        $this->reflector = $reflector;
        $this->logger = $logger;
    }

    public function build(FileList $fileList): void
    {
        iterator_to_array($this->index($fileList));
    }

    /**
     * @return Generator<string>
     */
    public function index(FileList $fileList): Generator
    {
        $this->logger->info(sprintf(
            'Last update: %s (%s)',
            $this->index->lastUpdate(),
            $this->formatTimestamp()
        ));

        yield from $this->doIndex($fileList);

        $this->index->write()->timestamp();
    }

    /**
     * @return Generator<string>
     */
    private function doIndex(FileList $fileList): Generator
    {
        $count = 0;
        foreach ($fileList as $fileInfo) {
            assert($fileInfo instanceof SplFileInfo);

            $this->logger->debug(sprintf('Indexing: %s', $fileInfo->getPathname()));

            try {
                $this->indexClasses(
                    $fileInfo,
                    $this->reflector->reflectClassesIn(
                        SourceCode::fromPathAndString(
                            $fileInfo->getPathname(),
                            file_get_contents($fileInfo->getPathname())
                        )
                    )
                );
            } catch (SourceNotFound $e) {
                $this->logger->error($e->getMessage());
            }

            yield $fileInfo->getPathname();

            $count++;
        }

        return $count > 0;
    }

    /**
     * @param ReflectionClassCollection<ReflectionClassLike> $classes
     */
    private function indexClasses(SplFileInfo $fileInfo, ReflectionClassCollection $classes): void
    {
        $mtime = $fileInfo->getMTime();
        foreach ($classes as $reflectionClass) {
            $this->createOrGetClassRecord($reflectionClass, $mtime);
            $this->updateClassRelations(
                $reflectionClass
            );
        }
    }

    private function createOrGetClassRecord(ReflectionClassLike $reflectionClass, int $mtime): ?ClassRecord
    {
        assert($reflectionClass instanceof ReflectionClassLike);
        
        $name = $reflectionClass->name()->full();
        
        if (empty($name)) {
            return null;
        }

        $name = FullyQualifiedName::fromString($name);

        if ($class = $this->index->query()->class($name)) {
            return $class;
        }
        
        $record = Invoke::new(ClassRecord::class, [
            'lastModified' => $mtime,
            'fqn' => $name,
            'type' => WorseUtil::classType($reflectionClass),
            'filePath' => $reflectionClass->sourceCode()->path(),
            'start' => ByteOffset::fromInt($reflectionClass->position()->start()),
        ]);

        $this->index->write()->class($record);

        return $record;
    }

    private function updateClassRelations(ReflectionClassLike $classLike): void
    {
        $classRecord = $this->index->query()->class(FullyQualifiedName::fromString($classLike->name()));
        $this->removeExistingReferences($classRecord);

        if ($classLike instanceof ReflectionInterface) {
            $this->updateClassImplementations($classLike, iterator_to_array($classLike->parents()));
        }

        if ($classLike instanceof ReflectionClass) {
            $this->updateClassImplementations($classLike, iterator_to_array($classLike->interfaces()));
            $this->updateClassImplementations($classLike, $this->descendents($classLike));
        }
    }

    /**
     * @param ReflectionCollection<ReflectionClassLike> $implementedClasses
     * @param array<ReflectionClassLike> $implementedClasses
     */
    private function updateClassImplementations(
        ReflectionClassLike $classReflection,
        array $implementedClasses
    ): void {
        $classRecord = $this->createOrGetClassRecord($classReflection, 0);

        foreach ($implementedClasses as $implementedClass) {
            $implementedFqn = FullyQualifiedName::fromString(
                $implementedClass->name()->full()
            );

            $mtime = filemtime($implementedClass->sourceCode()->path());
            $implementedRecord = $this->createOrGetClassRecord($implementedClass, $mtime ?: 0);

            if (null === $implementedRecord) {
                continue;
            }

            $classRecord->addImplements($implementedClass);
            $implementedRecord->addImplementation($classReflection);
            $this->index->write()->class($implementedRecord);
        }

        $this->index->write()->class($classRecord);
    }

    /**
     * @return array<ReflectionClass>
     */
    private function descendents(ReflectionClass $classLike): array
    {
        $parents = [];
        while ($parent = $classLike->parent()) {
            // avoid self-referencing classes
            if (array_key_exists($parent->name()->full(), $parents)) {
                break;
            }
            $parents[$parent->name()->full()] = $parent;
            $classLike = $parent;
        }
        return array_values($parents);
    }

    private function formatTimestamp(): string
    {
        $format = date('c', $this->index->lastUpdate());
        if (!$format) {
            throw new RuntimeException('This never happens');
        }
        return $format;
    }

    private function removeExistingReferences(ClassRecord $classRecord): void
    {
        foreach ($classRecord->implementedClasses() as $implementedClass) {
            $implementedRecord = $this->index->query()->class(
                FullyQualifiedName::fromString($implementedClass)
            );
            $implementedRecord->removeClass($classRecord->fqn());
            $this->index->write()->class($implementedRecord);
        }
    }
}
