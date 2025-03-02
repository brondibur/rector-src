<?php

namespace Rector\Tests\DowngradePhp56\Rector\CallLike\DowngradeArgumentUnpackingRector\Fixture;

final class NewStatic extends stdClass
{
    public function run(array $items)
    {
        return new static(...$items);
    }
}

?>
-----
<?php

namespace Rector\Tests\DowngradePhp56\Rector\CallLike\DowngradeArgumentUnpackingRector\Fixture;

final class NewStatic extends stdClass
{
    public function run(array $items)
    {
        return (new \ReflectionClass(get_called_class()))->newInstanceArgs($items);
    }
}

?>
