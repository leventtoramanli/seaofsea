<?php
require_once __DIR__ . '/../handlers/CRUDHandlers.php';

use Illuminate\Database\Capsule\Manager as Capsule;

class ShipHandler {
    private $crud;
    private static $logger;
    private static $loggerInfo;

    public function __construct() {
        $this->crud = new CRUDHandler();
        if (!self::$logger) self::$logger = getLogger();
        if (!self::$loggerInfo) self::$loggerInfo = getLoggerInfo();
    }

    private function buildResponse(bool $success, string $message, array $data = [], bool $showMessage = false, array $errors = []): array {
        return [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'showMessage' => $showMessage,
        ];
    }

    /**
     * Geminin tiplerini getirir.
     */
    public function getShipTypes(): array {
        try {
            $types = $this->crud->read(
                'ship_types', // Tablonun adı
                [],
                ['id', 'name', 'description'], // Hangi kolonlar dönecek
                true,
                [],
                [],
                ['orderBy' => ['name' => 'ASC']],
                true // Array formatında döndür
            );

            if (empty($types)) {
                return $this->buildResponse(false, 'No ship types found.', []);
            }

            return $this->buildResponse(true, 'Ship types retrieved successfully.', $types);
        } catch (Exception $e) {
            self::$logger->error('Error fetching ship types.', ['exception' => $e]);
            return $this->buildResponse(false, 'Error fetching ship types.', [], true, ['exception' => $e->getMessage()]);
        }
    }

    // İleride buraya yeni fonksiyonlar eklenebilir (örneğin: createShipType, deleteShipType gibi)
}
