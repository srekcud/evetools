<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\SharedAppraisalController;
use App\Entity\SharedShoppingList;
use App\Repository\SharedShoppingListRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SharedAppraisalControllerTest extends TestCase
{
    private SharedShoppingListRepository&MockObject $repository;
    private SharedAppraisalController $controller;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SharedShoppingListRepository::class);
        $this->controller = new SharedAppraisalController($this->repository);
    }

    public function testExpiredTokenReturns404(): void
    {
        $this->repository->method('findByToken')->willReturn(null);

        $request = Request::create('https://evetools.example.com/s/expired123');
        $response = ($this->controller)('expired123', $request);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertStringContainsString('expired or does not exist', $response->getContent());
    }

    public function testValidTokenReturnsOgMetaTags(): void
    {
        $sharedList = $this->createSharedList([
            'items' => [
                ['typeName' => 'Tritanium', 'quantity' => 1000],
                ['typeName' => 'Pyerite', 'quantity' => 500],
            ],
            'totals' => [
                'sellTotal' => 5_510_000_000.0,
                'buyTotal' => 1_900_000_000.0,
                'splitTotal' => 3_710_000_000.0,
            ],
        ]);

        $this->repository->method('findByToken')->willReturn($sharedList);

        $request = Request::create('https://evetools.example.com/s/abc123token');
        $response = ($this->controller)('abc123token', $request);

        $content = $response->getContent();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('og:site_name', $content);
        $this->assertStringContainsString('5.51B Sell', $content);
        $this->assertStringContainsString('1.90B Buy', $content);
        $this->assertStringContainsString('3.71B Split', $content);
        $this->assertStringContainsString('Tritanium x 1,000', $content);
        $this->assertStringContainsString('Pyerite x 500', $content);
        $this->assertStringContainsString('window.location.replace', $content);
        $this->assertStringContainsString('/appraisal/shared/abc123token', $content);
    }

    public function testWeightedTotalsPreferred(): void
    {
        $sharedList = $this->createSharedList([
            'items' => [
                ['typeName' => 'Tritanium', 'quantity' => 100],
            ],
            'totals' => [
                'sellTotal' => 1_000_000.0,
                'buyTotal' => 500_000.0,
                'splitTotal' => 750_000.0,
                'sellTotalWeighted' => 2_000_000.0,
                'buyTotalWeighted' => 1_000_000.0,
                'splitTotalWeighted' => 1_500_000.0,
            ],
        ]);

        $this->repository->method('findByToken')->willReturn($sharedList);

        $request = Request::create('https://evetools.example.com/s/weighted123');
        $response = ($this->controller)('weighted123', $request);

        $content = $response->getContent();

        $this->assertStringContainsString('2.00M Sell', $content);
        $this->assertStringContainsString('1.00M Buy', $content);
        $this->assertStringContainsString('1.50M Split', $content);
    }

    public function testDescriptionTruncatedToFiveItems(): void
    {
        $items = [];
        for ($i = 1; $i <= 8; $i++) {
            $items[] = ['typeName' => 'Item ' . $i, 'quantity' => $i * 100];
        }

        $sharedList = $this->createSharedList([
            'items' => $items,
            'totals' => ['sellTotal' => 100_000.0, 'buyTotal' => 50_000.0, 'splitTotal' => 75_000.0],
        ]);

        $this->repository->method('findByToken')->willReturn($sharedList);

        $request = Request::create('https://evetools.example.com/s/many123');
        $response = ($this->controller)('many123', $request);

        $content = $response->getContent();

        $this->assertStringContainsString('Item 1 x 100', $content);
        $this->assertStringContainsString('Item 5 x 500', $content);
        $this->assertStringNotContainsString('Item 6', $content);
        $this->assertStringContainsString('... and 3 more items', $content);
    }

    public function testFormatIskBillions(): void
    {
        $sharedList = $this->createSharedList([
            'items' => [],
            'totals' => ['sellTotal' => 12_345_678_901.0, 'buyTotal' => 0, 'splitTotal' => 0],
        ]);

        $this->repository->method('findByToken')->willReturn($sharedList);

        $request = Request::create('https://evetools.example.com/s/b123');
        $response = ($this->controller)('b123', $request);

        $this->assertStringContainsString('12.35B Sell', $response->getContent());
    }

    public function testFormatIskMillions(): void
    {
        $sharedList = $this->createSharedList([
            'items' => [],
            'totals' => ['sellTotal' => 5_678_901.0, 'buyTotal' => 0, 'splitTotal' => 0],
        ]);

        $this->repository->method('findByToken')->willReturn($sharedList);

        $request = Request::create('https://evetools.example.com/s/m123');
        $response = ($this->controller)('m123', $request);

        $this->assertStringContainsString('5.68M Sell', $response->getContent());
    }

    public function testFormatIskThousands(): void
    {
        $sharedList = $this->createSharedList([
            'items' => [],
            'totals' => ['sellTotal' => 45_678.0, 'buyTotal' => 0, 'splitTotal' => 0],
        ]);

        $this->repository->method('findByToken')->willReturn($sharedList);

        $request = Request::create('https://evetools.example.com/s/k123');
        $response = ($this->controller)('k123', $request);

        $this->assertStringContainsString('45.68K Sell', $response->getContent());
    }

    public function testFormatIskSmallValues(): void
    {
        $sharedList = $this->createSharedList([
            'items' => [],
            'totals' => ['sellTotal' => 999.0, 'buyTotal' => 0, 'splitTotal' => 0],
        ]);

        $this->repository->method('findByToken')->willReturn($sharedList);

        $request = Request::create('https://evetools.example.com/s/small123');
        $response = ($this->controller)('small123', $request);

        $this->assertStringContainsString('999 Sell', $response->getContent());
    }

    public function testOgUrlUsesRequestHost(): void
    {
        $sharedList = $this->createSharedList([
            'items' => [],
            'totals' => ['sellTotal' => 0, 'buyTotal' => 0, 'splitTotal' => 0],
        ]);

        $this->repository->method('findByToken')->willReturn($sharedList);

        $request = Request::create('https://eve.mytools.com/s/host123');
        $response = ($this->controller)('host123', $request);

        $this->assertStringContainsString('https://eve.mytools.com/s/host123', $response->getContent());
    }

    public function testHtmlEscapesSpecialCharacters(): void
    {
        $sharedList = $this->createSharedList([
            'items' => [
                ['typeName' => 'Item <script>alert("xss")</script>', 'quantity' => 1],
            ],
            'totals' => ['sellTotal' => 1000.0, 'buyTotal' => 500.0, 'splitTotal' => 750.0],
        ]);

        $this->repository->method('findByToken')->willReturn($sharedList);

        $request = Request::create('https://evetools.example.com/s/xss123');
        $response = ($this->controller)('xss123', $request);

        $content = $response->getContent();

        $this->assertStringNotContainsString('<script>alert', $content);
        $this->assertStringContainsString('&lt;script&gt;', $content);
    }

    public function testContentTypeIsHtml(): void
    {
        $this->repository->method('findByToken')->willReturn(null);

        $request = Request::create('https://evetools.example.com/s/any123');
        $response = ($this->controller)('any123', $request);

        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
    }

    public function testFiveItemsExactlyNoMoreLine(): void
    {
        $items = [];
        for ($i = 1; $i <= 5; $i++) {
            $items[] = ['typeName' => 'Item ' . $i, 'quantity' => $i * 10];
        }

        $sharedList = $this->createSharedList([
            'items' => $items,
            'totals' => ['sellTotal' => 100.0, 'buyTotal' => 50.0, 'splitTotal' => 75.0],
        ]);

        $this->repository->method('findByToken')->willReturn($sharedList);

        $request = Request::create('https://evetools.example.com/s/five123');
        $response = ($this->controller)('five123', $request);

        $content = $response->getContent();

        $this->assertStringContainsString('Item 5 x 50', $content);
        $this->assertStringNotContainsString('more items', $content);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createSharedList(array $data): SharedShoppingList
    {
        $sharedList = new SharedShoppingList();
        $sharedList->setData($data);

        return $sharedList;
    }
}
