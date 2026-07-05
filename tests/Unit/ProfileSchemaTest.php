<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Affiliation;
use App\Domain\ProfileSchema;
use PHPUnit\Framework\TestCase;

final class ProfileSchemaTest extends TestCase
{
    public function testAicuraValidProfilePasses(): void
    {
        $errors = ProfileSchema::validate(Affiliation::Aicura, [
            'age' => 30,
            'sex' => 'M',
            'where_from' => 'Seoul',
        ]);

        self::assertSame([], $errors);
    }

    public function testAicopiaValidProfilePasses(): void
    {
        $errors = ProfileSchema::validate(Affiliation::Aicopia, [
            'gender' => 'female',
            'birthday' => '1990-05-01',
            'zipcode' => '06236',
            'address1' => '서울시 강남구',
            'address2' => '테헤란로 1',
        ]);

        self::assertSame([], $errors);
    }

    public function testPartialProfileIsAllowed(): void
    {
        self::assertSame([], ProfileSchema::validate(Affiliation::Aicura, ['age' => 20]));
        self::assertSame([], ProfileSchema::validate(Affiliation::Aicura, []));
    }

    public function testUnknownKeyIsRejected(): void
    {
        $errors = ProfileSchema::validate(Affiliation::Aicura, ['nickname' => 'x']);

        self::assertNotSame([], $errors);
    }

    public function testWrongTypeIsRejected(): void
    {
        $ageError = ProfileSchema::validate(Affiliation::Aicura, ['age' => 'thirty']);
        self::assertNotSame([], $ageError);

        $birthdayError = ProfileSchema::validate(Affiliation::Aicopia, ['birthday' => 'not-a-date']);
        self::assertNotSame([], $birthdayError);
    }

    public function testNegativeAgeIsRejected(): void
    {
        self::assertNotSame([], ProfileSchema::validate(Affiliation::Aicura, ['age' => -1]));
    }

    /**
     * @return iterable<string, array{Affiliation}>
     */
    public static function fieldlessAffiliations(): iterable
    {
        yield 'aicreo' => [Affiliation::Aicreo];
        yield 'aivance' => [Affiliation::Aivance];
        yield 'ailicet' => [Affiliation::Ailicet];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fieldlessAffiliations')]
    public function testFieldlessAffiliationRejectsAnyProfileField(Affiliation $affiliation): void
    {
        self::assertSame([], ProfileSchema::validate($affiliation, []));
        self::assertNotSame([], ProfileSchema::validate($affiliation, ['age' => 1]));
    }

    public function testAllowedFieldsListing(): void
    {
        self::assertSame(['age', 'sex', 'where_from'], ProfileSchema::allowedFields(Affiliation::Aicura));
        self::assertSame([], ProfileSchema::allowedFields(Affiliation::Aicreo));
    }
}
