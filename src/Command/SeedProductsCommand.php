<?php

declare(strict_types=1);

namespace App\Command;

use App\Document\Product;
use App\Repository\ProductRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:products:seed',
    description: 'Insere des produits electroniques de demonstration (idempotent sur le SKU).',
)]
final class SeedProductsCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $dm,
        private readonly ProductRepository $products,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = 0;

        foreach ($this->samples() as $data) {
            if (null !== $this->products->findOneBy(['sku' => $data['sku']])) {
                $io->writeln(\sprintf('  = %s (existe deja)', $data['sku']));
                continue;
            }

            $product = (new Product())
                ->setSku($data['sku'])
                ->setName($data['name'])
                ->setBrand($data['brand'])
                ->setCategory($data['category'])
                ->setDescription($data['description'])
                ->setPrice($data['price'])
                ->setCurrency('EUR')
                ->setStock($data['stock'])
                ->setWarrantyMonths($data['warrantyMonths'])
                ->setSpecifications($data['specifications']);

            $this->dm->persist($product);
            ++$created;
            $io->writeln(\sprintf('  + %s', $data['sku']));
        }

        $this->dm->flush();
        $io->success(\sprintf('%d produit(s) cree(s).', $created));

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function samples(): array
    {
        return [
            [
                'sku' => 'APL-IPH15-128',
                'name' => 'iPhone 15 128 Go Noir',
                'brand' => 'Apple',
                'category' => 'smartphone',
                'description' => 'Ecran 6,1" OLED Super Retina XDR, port USB-C, puce A16 Bionic.',
                'price' => 869.00,
                'stock' => 42,
                'warrantyMonths' => 24,
                'specifications' => ['ram_gb' => 6, 'storage_gb' => 128, 'screen_in' => 6.1, '5g' => true, 'os' => 'iOS 17'],
            ],
            [
                'sku' => 'SMS-S24U-256',
                'name' => 'Samsung Galaxy S24 Ultra 256 Go',
                'brand' => 'Samsung',
                'category' => 'smartphone',
                'description' => 'Ecran 6,8" QHD+ 120 Hz, S Pen integre, zoom optique x5.',
                'price' => 1299.00,
                'stock' => 18,
                'warrantyMonths' => 24,
                'specifications' => ['ram_gb' => 12, 'storage_gb' => 256, 'screen_in' => 6.8, '5g' => true, 'os' => 'Android 14'],
            ],
            [
                'sku' => 'DEL-XPS13-9340',
                'name' => 'Dell XPS 13 9340',
                'brand' => 'Dell',
                'category' => 'laptop',
                'description' => 'Ultraportable 13,4" InfinityEdge, Intel Core Ultra 7, 16 Go LPDDR5.',
                'price' => 1599.00,
                'stock' => 9,
                'warrantyMonths' => 36,
                'specifications' => ['ram_gb' => 16, 'storage_gb' => 512, 'cpu' => 'Core Ultra 7 155H', 'screen_in' => 13.4],
            ],
            [
                'sku' => 'SNY-WH1000XM5',
                'name' => 'Sony WH-1000XM5',
                'brand' => 'Sony',
                'category' => 'audio',
                'description' => 'Casque circum-aural a reduction de bruit active, autonomie 30 h.',
                'price' => 379.00,
                'stock' => 55,
                'warrantyMonths' => 24,
                'specifications' => ['type' => 'circum-aural', 'anc' => true, 'battery_h' => 30, 'bluetooth' => '5.2'],
            ],
            [
                'sku' => 'LG-OLED55C4',
                'name' => 'LG OLED55C4 55"',
                'brand' => 'LG',
                'category' => 'tv',
                'description' => 'TV OLED evo 4K 55", 120 Hz, HDMI 2.1, webOS.',
                'price' => 1490.00,
                'stock' => 6,
                'warrantyMonths' => 24,
                'specifications' => ['panel' => 'OLED evo', 'size_in' => 55, 'resolution' => '3840x2160', 'hz' => 120],
            ],
            [
                'sku' => 'APL-WATCH-S9-45',
                'name' => 'Apple Watch Series 9 45 mm',
                'brand' => 'Apple',
                'category' => 'wearable',
                'description' => 'Montre connectee GPS, ecran Retina toujours actif, puce S9.',
                'price' => 449.00,
                'stock' => 27,
                'warrantyMonths' => 24,
                'specifications' => ['case_mm' => 45, 'gps' => true, 'cellular' => false, 'water_resist_m' => 50],
            ],
        ];
    }
}
