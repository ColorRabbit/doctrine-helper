<?php

namespace Doctrine\Helper\Driver;

use Doctrine\DBAL\Connection;

abstract class Driver
{
    public string $tableName = '';
    public array $tableInfo = [];
    public string $entityName = "";

    public function __construct(
        public string     $entityNamespace,
        public string     $repositoryNamespace,
        public string     $type,
        public string     $tableList,
        public string     $ucfirst,
        public string     $withoutTablePrefix,
        public string     $database,
        public string     $entityDir,
        public string     $repositoryDir,
        public Connection $connection,
    )
    {
        if (empty($entityNamespace)) {
            $this->entityNamespace = "App\\Entity";
        }
        if (empty($repositoryNamespace)) {
            $this->repositoryNamespace = "App\\Repository";
        }
        if (empty($type)) {
            $this->type = "attribute";
        }
    }

    abstract public function getTableList();

    abstract public function getTableInfo();

    abstract public function makeIndexes();

    abstract public function makeProperties();

    public static function create(
        string     $entityNamespace,
        string     $repositoryNamespace,
        string     $type,
        string     $tableList,
        string     $ucfirst,
        string     $withoutTablePrefix,
        string     $database,
        string     $entityDir,
        string     $repositoryDir,
        Connection $connection,
    )
    {
        return new static(
            $entityNamespace,
            $repositoryNamespace,
            $type,
            $tableList,
            $ucfirst,
            $withoutTablePrefix,
            $database,
            $entityDir,
            $repositoryDir,
            $connection,
        );
    }

    public function import(): void
    {
        $tableList = static::getTableList();
        if (!empty($this->tableList)) {
            $tables = trim($this->tableList, ',');
            $tables = explode(',', $tables);
            $diff = array_diff($tables, $tableList);
            if (!empty($diff)) {
                throw new \Exception("Tables is not exist: " . implode(", ", $diff));
            }
            $tableList = $tables;
        }
        foreach ($tableList as $tableName) {
            $this->tableName = $tableName;
            $this->do($this->tableName);
        }
    }

    public function do($tableName): void
    {
        $this->tableInfo = $this->getTableInfo($tableName);
        $this->makeEntity($tableName);
        $this->makeRepository();
    }

    private function makeRepository(): void
    {
        $fileName = $this->entityName . "Repository.php";
        $filePath = $this->repositoryDir . $fileName;
        if (!file_exists($filePath)) {
            $content = <<<EOF
<?php

namespace {$this->repositoryNamespace};

use {$this->entityNamespace}\\{$this->entityName};
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<{$this->entityName}>
 *
 * @method {$this->entityName}|null find(\$id, \$lockMode = null, \$lockVersion = null)
 * @method {$this->entityName}|null findOneBy(array \$criteria, array \$orderBy = null)
 * @method {$this->entityName}[]    findAll()
 * @method {$this->entityName}[]    findBy(array \$criteria, array \$orderBy = null, \$limit = null, \$offset = null)
 */
class {$this->entityName}Repository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry \$registry)
    {
        parent::__construct(\$registry, {$this->entityName}::class);
    }
    
    /**
     * @return int|mixed|string
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function getCount()
    {
        return \$this->createQueryBuilder('p')
            ->select('count(p) as count')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array \$where
     * @param array \$order
     * @param int \$length
     * @param int \$page
     * @return array
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function list(array \$where = [], array \$order = [], int \$length = 10, int \$page = 1): array
    {
        \$list = \$this->createQueryBuilder('p');

        \$count = \$list->select('count(p) as count')
            ->getQuery()->getSingleScalarResult();
        \$list = \$list->select('p');
        if (!empty(\$order)) {
            foreach (\$order as \$key => \$value) {
                \$list->addOrderBy(\$key, \$value);
            }
        }

        if (\$length == -1) {
            \$list = \$list->getQuery()->getResult();

            return ['count' => \$count, 'list' => \$list];
        }

        \$list = \$list->setFirstResult(\$length * (\$page - 1))
            ->setMaxResults(\$length)
            ->getQuery()
            ->getResult();

        return ['count' => \$count, 'list' => \$list];
    }


}

EOF;
            file_put_contents($filePath, $content);
        } else {
            $content = file_get_contents($filePath);
            if (!str_contains($content, "@extends")) {
                $replace = <<<EOF
/**
 * @extends ServiceEntityRepository<{$this->entityName}>
 *
 * @method {$this->entityName}|null find(\$id, \$lockMode = null, \$lockVersion = null)
 * @method {$this->entityName}|null findOneBy(array \$criteria, array \$orderBy = null)
 * @method {$this->entityName}[]    findAll()
 * @method {$this->entityName}[]    findBy(array \$criteria, array \$orderBy = null, \$limit = null, \$offset = null)
 */
class {$this->entityName}Repository extends ServiceEntityRepository
EOF;
                $origin = "class {$this->entityName}Repository extends ServiceEntityRepository";
                $newContent = str_replace($origin, $replace, $content);
                file_put_contents($filePath, $newContent);
            }
        }
    }

    private function makeEntity(string $tableName): void
    {
        if (!empty($this->withoutTablePrefix) && str_starts_with($this->tableName, $this->withoutTablePrefix)) {
            $tableName = substr($tableName, strlen($this->withoutTablePrefix));
        }
        $entityName = $this->upperName($tableName);
        $this->entityName = $entityName;
        $fileName = $this->entityName . ".php";
        $filePath = $this->entityDir . $fileName;
        $indexes = static::makeIndexes();
        [$properties, $getSet] = static::makeProperties();
        $content = <<<EOF
<?php

namespace {$this->entityNamespace};

use {$this->repositoryNamespace}\\{$entityName}Repository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: '{$this->tableName}')]{$indexes}
#[ORM\Entity(repositoryClass: {$entityName}Repository::class)]
class {$entityName}
{
{$properties}

{$getSet}
}

EOF;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        file_put_contents($filePath, $content);
    }

    public function upperName(string $name): string
    {
        return str_replace("_", "", ucwords(strtolower($name), '_'));
    }

    public function upper(string $name): string
    {
        return lcfirst($this->upperName($name));
    }
}