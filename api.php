<?php 

    public function export(Request $request)
    {
        // Получаем идентификатор отчета из запроса
        $reportId = $request->input('report_id');
        $report = Sale::findOrFail($reportId);

        // Получаем позиции продажи для выбранного отчета
        $positions = Sale::where('realizationreport_id', $report->realizationreport_id)->get();

        // Формируем данные для отправки в МойСклад
        $requestData = [
            "name" => strval($report->realizationreport_id),
            "organization" => [
                "meta" => [
                    "href" => "https://online.moysklad.ru/api/remap/1.2/entity/organization/219067c3-f850-11eb-0a80-028b0004d334",
                    "type" => "organization",
                    "mediaType" => "application/json"
                ]
            ],
            "code" => strval($report->realizationreport_id),
            "moment" => $report->create_dt . " 00:00:00",
            "description" => strval($report->realizationreport_id),
            "applicable" => true,
            "vatEnabled" => true,
            "agent" => [
                "meta" => [
                    "href" => "https://online.moysklad.ru/api/remap/1.2/entity/counterparty/5fc8b544-29c5-11eb-0a80-05150000c9ca",
                    "type" => "counterparty",
                    "mediaType" => "application/json"
                ]
            ],
            "state" => [
                "meta" => [
                    "href" => "https://online.moysklad.ru/api/remap/1.2/entity/customerorder/metadata/states/d748d70e-2767-11eb-0a80-0648001e71ff",
                    "type" => "state",
                    "mediaType" => "application/json"
                ]
            ],
            "positions" => []
        ];

        // Группируем позиции продажи по уникальным комбинациям UUID и цены
        $groupedPositions = [];
        foreach ($positions as $position) {
            $uuid = $position->uuid;
            $quantity = $position->quantity;
            $price = floatval(str_replace(',', '.', str_replace('.', '', $position->retail_amount)));

            // Используем комбинацию UUID и цены продажи (retail_amount) в качестве ключа
            $key = $uuid . '_' . $price;

            if (isset($groupedPositions[$key])) {
                $groupedPositions[$key]['quantity'] += $quantity;
            } else {
                $groupedPositions[$key] = [
                    'uuid' => $uuid,
                    'quantity' => $quantity,
                    'price' => $price,
                ];
            }
        }

        // Определяем параметры для листания
        $perPage = 1000;
        $responseSuccessful = true;
        $offset = 0;
        $totalPositions = count($groupedPositions);

        // Выполняем листание для получения всех позиций
        while ($offset < $totalPositions) {
            $pagePositions = array_slice($groupedPositions, $offset, $perPage);

            $requestData['positions'] = [];
            foreach ($pagePositions as $groupedPosition) {
                // Формируем данные для каждой позиции в запросе
                $uuid = $groupedPosition['uuid'];
                $quantity = $groupedPosition['quantity'];
                $price = $groupedPosition['price'];

                $requestData['positions'][] = [
                    "quantity" => $quantity,
                    "price" => $price,
                    "discount" => 0,
                    "vat" => 0,
                    "assortment" => [
                        "meta" => [
                            "href" => "https://online.moysklad.ru/api/remap/1.2/entity/product/{$uuid}",
                            "type" => "product",
                            "mediaType" => "application/json"
                        ]
                    ],
                    "reserve" => $quantity
                ];
            }

            // Отправляем запрос только с частью позиций (не более 1000)
            $response = Http::withToken('b91d02034c8b8c9d57433127fbdc493deff76069')
                ->post('https://online.moysklad.ru/api/remap/1.2/entity/customerorder', $requestData);

            if (!$response->successful()) {
                $responseSuccessful = false;
                $errorMessage = $response->json()['errors'][0]['error'];
                Session::flash('error', 'Ошибка при создании отчета: ' . $errorMessage);
                break; // Прерываем цикл в случае ошибки или обрабатываем ошибку по необходимости
            }

            // Обновляем смещение для следующей страницы
            $offset += $perPage;
        }

        if ($responseSuccessful) {
            // Если все запросы успешно выполнены, то отчет создан для всех позиций
            Session::flash('success', 'Отчеты успешно созданы в МойСклад!');
        }

        return redirect()->back();
    }
