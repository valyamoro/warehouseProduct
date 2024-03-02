<?php
declare(strict_types=1);
\error_reporting(-1);

use App\Database\DatabaseConfiguration;
use App\Database\DatabasePDOConnection;
use App\Database\PDODriver;

require_once __DIR__ . '/vendor/autoload.php';

$session = new \App\core\Http\Session();
$session->start(true);

$request = new \App\core\Http\Request();

$configuration = require __DIR__ . '/config/db.php';
$dataBaseConfiguration = new DatabaseConfiguration(...$configuration);
$dataBasePDOConnection = new DatabasePDOConnection($dataBaseConfiguration);
$pdoDriver = new PDODriver($dataBasePDOConnection->connection());

$productRepository = new \App\Services\Product\Repositories\ProductRepository($pdoDriver);
$productService = new \App\Services\Product\ProductService($productRepository, $session);

$storageRepository = new \App\Services\Storage\Repositories\StorageRepository($pdoDriver);
$storageService = new \App\Services\Storage\StorageService($storageRepository, $session);

if ($request->getMethod('quantity')) {
    $data = $request->getMethod();
    foreach ($data as $key => $value) {
        $data[$key] = \htmlspecialchars(\strip_tags(\trim($value)));
    }

    $data = [
        'product_id' => (int)$data['product_id'],
        'from_storage_id' => (int)$data['from_storage_id'],
        'to_storage_id' => (int)$data['to_storage_id'],
        'move_quantity' => (int)$data['quantity'],
    ];

    $productStorageData = $productService->getAllProductStorage($data['product_id'], $data['from_storage_id']);
    $productValidator = new \App\Validations\ProductValidator(
        $data['move_quantity'],
        $productStorageData['quantity'],
        ['from' => $data['from_storage_id'], 'to' => $data['to_storage_id']],
    );

    if (!$productValidator->validate()) {
        $session->setFlash(['validate_errors' => $productValidator->getErrors()]);
    } else {
        $productData = $productService->getById($data['product_id']);
        $product = new \App\Models\Product(
            $productData['title'],
            $productData['price'],
            (int)$productData['quantity'],
            $productData['created_at'],
            $productData['updated_at'],
        );
        $product->setId($productData['id']);

        $productStorage = new \App\Models\ProductStorage(
            $data['from_storage_id'],
            $data['to_storage_id'],
            $data['move_quantity'],
        );

        $productStorage = $productService->getAllAboutProduct($product, $productStorage);
        if (\is_null($productStorage)) {
            $session->setFlash(['error' => 'Что-то пошло не так, пожалуйста обратитесь к администратору сайта.']);
        } else {
            $productStorage = $storageService->moveProduct($product, $productStorage);
            if (!\is_null($productStorage)) {
                $productStorage = $storageService->getInfoAboutProductMovement($product, $productStorage);
                if ($storageService->saveHistory($product->getId(), $productStorage)) {
                    $msg = "Вы успешно переместили продукт с номером {$data['product_id']} со склада под номером {$data['from_storage_id']}
                    на склад под номером {$data['to_storage_id']} в количестве {$data['move_quantity']} штук.";
                    $session->setFlash(['success' => $msg]);
                } else {
                    $this->session->setFlash(['error' => 'История о перемещении товара не была сохранена! Пожалуйста, обратитесь к администратору сайта']);
                    return false;
                }
            }
        }
    }

    \header('Location: /');
    die;
}

$products = [];
$storages = [];
$historyMovementProducts = [];
$data = $productService->getAll();
foreach ($data as $value) {
    $product = new \App\Models\Product(
        $value['title'],
        (int)$value['price'],
        (int)$value['quantity'],
        $value['created_at'],
        $value['updated_at'],
    );
    $product->setId($value['id']);
    $products[] = $product;
    $storage = new App\Models\Storage(
        $value['name'],
        $value['storage_created'],
        $value['storage_updated'],
    );

    $storage->setId($value['storage_id']);
    $storage->setProduct($product);
    $storages[] = $storage;

    $historyMovementProducts[$product->getId()] = $storageService->getAllHistoryAboutMovementProduct($product->getId());
}


$storagesList = $storageService->getAll();
$productStorages = [];

foreach ($historyMovementProducts as $key => $history) {
    foreach ($history as $value) {
        $productStorage = new \App\Models\ProductStorage(
            $value['from_storage_id'],
            $value['to_storage_id'],
            $value['move_quantity'],
        );

        $productStorage->setNowQuantityToStorage($value['now_quantity_to_storage']);
        $productStorage->setPastQuantityToStorage($value['past_quantity_from_storage']);
        $productStorage->setPastQuantityToStorage($value['past_quantity_to_storage']);
        $productStorage->setNowQuantityToStorage($value['now_quantity_to_storage']);
        $productStorage->setPastQuantityFromStorage($value['past_quantity_from_storage']);
        $productStorage->setNowQuantityFromStorage($value['now_quantity_from_storage']);

        foreach ($products as $product) {
            if ($product->getId() === $key) {
                $productStorage->setProduct($product);
            }
        }

        $collectionStorages = [];
        foreach ($storagesList as $storageData) {
            $storage = new App\Models\Storage(
                $storageData['name'],
                $value['created_at'],
                $value['updated_at'],
            );

            $storage->setId($storageData['id']);
            $collectionStorages[] = $storage;
        }

        foreach ($collectionStorages as $storage) {
            if ($storage->getId() === $value['to_storage_id']) {
                $productStorage->setToStorage($storage);
            }
            if ($storage->getId() === $value['from_storage_id']) {
                $productStorage->setFromStorage($storage);
            }
        }

        $productStorages[$key][] = $productStorage;
    }
}


?>
<?php if ($request->getMethod('product_id') && $request->getMethod('from_storage_id')): ?>
    <form id="myForm" action="" method="POST">
        <div class="modal-body">
            Вы перемещаете продукт с айди: <?php echo $request->getMethod('product_id'); ?><br>
            Со склада с айди: <?php echo $request->getMethod('from_storage_id'); ?> <br>
            На склад: <br>
            <label for="to_storage_id"></label><select name="to_storage_id" id="to_storage_id">
                <?php foreach ($storagesList as $value): ?>
                    <option value="<?php echo $value['id'] ?>">
                        <?php echo $value['name'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <label for="quantity" class="form-label">Количество</label>
        <input type="text" name="quantity" id="numberInput" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
        <input type="hidden" name="product_id" value="<?php echo $request->getMethod('product_id'); ?>">
        <input type="hidden" name="from_storage_id" value="<?php echo $request->getMethod('from_storage_id'); ?>">
        <button type="submit" name="product_id" value="<?php echo $request->getMethod('product_id'); ?>"
                class="btn btn-primary">
            Переместить
        </button>
    </form>

    <script>
        document.getElementById("submitButton").addEventListener("click", function () {
            document.getElementById("myForm").submit();
        });
    </script>
<?php else: ?>
<?php if (!empty($session->getFlash()['validate_errors'])): ?>
    <?php foreach ($session->getFlash()['validate_errors'] as $error): ?>
        <?php echo \nl2br($error) . '<br>'; ?>
    <?php endforeach; ?>
    <?php unset($session->getFlash()['validate_errors']); ?>
    <br>
<?php endif; ?>
<?php if (!empty($session->getFlash()['success'])): ?>
    <?php echo \nl2br($session->getFlash()['success']) . '<br>'; ?>
    <?php unset($session->getFlash()['success']); ?>
    <br>
<?php endif; ?>
<?php if (!empty($session->getFlash()['error'])): ?>
    <?php echo \nl2br($session->getFlash()['error']) . '<br>'; ?>
    <?php unset($session->getFlash()['error']); ?>
    <br>
<?php endif; ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bootstrap demo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous">
</script>

<div id="myModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <div id="modalContent"></div>
    </div>
</div>
<script src="assets/script.js"></script>

</body>
<table class="table">
    <?php ?>
    <thead>
    <tr>
        <th scope="col">Айди продукта</th>
        <th scope="col">Название продукта</th>
        <th scope="col">Название склада</th>
        <th scope="col">Цена</th>
        <th scope="col">Количество</th>
        <th scope="col"></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($storages as $storage): ?>
        <tr>
            <td><?php echo $storage->getProduct()->getId() ?></td>
            <td><?php echo $storage->getProduct()->getTitle() ?></td>
            <td><?php echo $storage->getName(); ?> </td>
            <td><?php echo $storage->getProduct()->getPrice(); ?> </td>
            <td><?php echo $storage->getProduct()->getQuantity(); ?> </td>
            <td>
                <button class="openModalBtn" data-product-id="<?php echo $storage->getProduct()->getId(); ?>"
                        data-from-storage-id="<?php echo $storage->getId(); ?>">
                    Переместить
                </button>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<h3>История перемещений:</h3>
<table class="table">
    <thead>
    <tr>
        <th scope="col">Айди продукта</th>
        <th scope="col">История</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($productStorages as $key => $productStorage): ?>
        <?php foreach ($productStorage as $value): ?>
            <tr>
                <td><?php echo $key ?></td>
                <td><?php echo "{$value->getFromStorage()->getName()} {$value->getProduct()->getTitle()} был {$value->getPastQuantityFromStorage()}
                    стало {$value->getNowQuantityFromStorage()} | {$value->getFromStorage()->getCreatedAt()}<br>
                   {$value->getToStorage()->getName()} {$value->getProduct()->getTitle()} было {$value->getPastQuantityToStorage()}
                    перемещено {$value->getMoveQuantity()} стало {$value->getNowQuantityToStorage()} | {$value->getToStorage()->getCreatedAt()}"; ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
