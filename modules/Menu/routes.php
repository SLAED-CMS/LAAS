<?php

declare(strict_types=1);

return [
    ['GET', '/admin/menus', [\Laas\Modules\Menu\Controller\AdminMenusController::class, 'index']],
    ['GET', '/admin/menus/item/new', [\Laas\Modules\Menu\Controller\AdminMenusController::class, 'newItemForm']],
    ['GET', '/admin/menus/item/{id:\d+}/edit', [\Laas\Modules\Menu\Controller\AdminMenusController::class, 'editItemForm']],
    ['POST', '/admin/menus/item/save', [\Laas\Modules\Menu\Controller\AdminMenusController::class, 'saveItem']],
    ['POST', '/admin/menus/item/toggle', [\Laas\Modules\Menu\Controller\AdminMenusController::class, 'toggleItem']],
    ['POST', '/admin/menus/item/delete', [\Laas\Modules\Menu\Controller\AdminMenusController::class, 'deleteItem']],
];
