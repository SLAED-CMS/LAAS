<?php
declare(strict_types=1);

use Laas\Core\Validation\Validator;
use Laas\Database\DatabaseManager;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredAndString(): void
    {
        $validator = new Validator();
        $result = $validator->validate([
            'title' => '',
        ], [
            'title' => ['required', 'string'],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertSame('validation.required', $result->first('title'));
    }

    public function testMinMax(): void
    {
        $validator = new Validator();
        $result = $validator->validate([
            'title' => 'ab',
        ], [
            'title' => ['min:3', 'max:5'],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertSame('validation.min', $result->first('title'));
    }

    public function testSlugAndIn(): void
    {
        $validator = new Validator();
        $result = $validator->validate([
            'slug' => 'Bad Slug',
            'status' => 'invalid',
        ], [
            'slug' => ['slug'],
            'status' => ['in:draft,published'],
        ]);

        $this->assertFalse($result->isValid());
        $this->assertSame('validation.slug', $result->first('slug'));
        $this->assertSame('validation.in', $result->first('status'));
    }

    public function testUniqueRule(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT)');
        $pdo->exec("INSERT INTO pages (slug) VALUES ('exists')");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new \ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        $validator = new Validator();
        $result = $validator->validate([
            'slug' => 'exists',
        ], [
            'slug' => ['unique:pages,slug'],
        ], [
            'db' => $db,
        ]);

        $this->assertFalse($result->isValid());
        $this->assertSame('validation.unique', $result->first('slug'));
    }

    public function testReservedSlugRule(): void
    {
        $validator = new Validator();
        $rules = ['slug' => ['reserved_slug:admin,api']];

        $blocked = $validator->validate(['slug' => 'admin'], $rules);
        $this->assertFalse($blocked->isValid());
        $this->assertSame('validation.reserved_slug', $blocked->first('slug'));

        $allowed = $validator->validate(['slug' => 'about'], $rules);
        $this->assertTrue($allowed->isValid());
    }
}
