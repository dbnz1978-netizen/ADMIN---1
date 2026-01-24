<?php
/**
 * Файл: /admin/functions/jsondata.php
 * Безопасное обновление JSON данных пользователя
 * 
 * Функция сохраняет все существующие поля в JSON колонке 'data'
 * и обновляет только переданные значения. Автоматически добавляет
 * метку времени обновления.
 * 
 * @param PDO $pdo Объект подключения к базе данных
 * @param int $userId ID пользователя
 * @param array $newData Новые данные для обновления
 * @return string Закодированная JSON строка для сохранения в БД
 * 
 * @example
 * $updateData = ['first_name' => 'John', 'last_name' => 'Doe'];
 * $jsonData = updateUserJsonData($pdo, 123, $updateData);
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

function updateUserJsonData($pdo, $userId, $newData) {
    try {
        // Получаем текущие данные
        $stmt = $pdo->prepare("SELECT data FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $currentData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $existingData = json_decode($currentData['data'] ?? '{}', true) ?? [];
        
        // Объединяем данные (новые перезаписывают старые)
        $updatedData = array_merge($existingData, $newData);
        
        // Добавляем метку времени
        $updatedData['updated_at'] = date('Y-m-d H:i:s');
        
        return json_encode($updatedData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        
    } catch (Exception $e) {
        // В случае ошибки создаем новый JSON с переданными данными
        $newData['updated_at'] = date('Y-m-d H:i:s');
        return json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

/**
// Подготавливаем данные для обновления
$updateData = [
    'first_name' => $firstName,
    'last_name' => $lastName,
    'phone' => $phone,
    'images' => $selectedImages
];
        
// Безопасно обновляем JSON данные (сохраняем существующие поля)
$jsonData = updateUserJsonData($pdo, $user['id'], $updateData);
 */