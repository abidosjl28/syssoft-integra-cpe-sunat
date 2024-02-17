<?php

namespace App\Helpers;

use App\Src\SoapResult;
use App\Src\Sunat;
use DateTime;
use DOMDocument;
use Dotenv\Util\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SunatHelper
{

    public function __construct()
    {
    }

    public static function sendBillToSunat(string $fileName, DOMDocument $xml, $idVenta, $empresa)
    {
        $fileNameXml  = $fileName . '.xml';
        $path = 'sunat/' . $fileNameXml;

        // Storage::disk('files')->put($path, $xml->saveXML());
        Storage::put('files/'.$path, $xml->saveXML());
        Sunat::signDocument($fileNameXml);

        Sunat::createZip(
            Storage::path("files/sunat/" . $fileName . '.zip'),
            Storage::path("files/sunat/" . $fileNameXml),
            $fileNameXml
        );

        if (DB::table('empresa')->where('tipoEnvio', 1)->get()->isEmpty()) {
            $wdsl = Storage::path('wsdl/desarrollo/billService.wsdl');
        } else {
            $wdsl = Storage::path('wsdl/produccion/billService.wsdl');
        }

        $soapResult = new SoapResult($wdsl, $fileName);
        $soapResult->sendBill(Sunat::xmlSendBill(
            $empresa->documento,
            $empresa->usuarioSolSunat,
            $empresa->claveSolSunat,
            $fileName . '.zip',
            Sunat::generateBase64File(Storage::get('files/sunat/' . $fileName . '.zip'))
        ));

        if ($soapResult->isSuccess()) {
            $updateData = [
                "xmlSunat" => $soapResult->getCode(),
                "xmlDescripcion" => $soapResult->getDescription(),
            ];

            if ($soapResult->isAccepted()) {
                $updateData["codigoHash"] = $soapResult->getHashCode();
                $updateData["xmlGenerado"] = Sunat::getXmlSign();
            }

            DB::table("venta")
                ->where('idVenta', $idVenta)
                ->update($updateData);

            $responseData = [
                "state" => $soapResult->isSuccess(),
                "accept" => $soapResult->isAccepted(),
                "code" => $soapResult->getCode(),
                "description" => $soapResult->getDescription(),
            ];
        } else {
            $updateData = [
                "xmlSunat" => $soapResult->getCode(),
                "xmlDescripcion" => $soapResult->getDescription(),
            ];

            if ($soapResult->getCode() == "1033") {
                $updateData["xmlSunat"] = "0";
            }

            DB::table("venta")
                ->where('idVenta', $idVenta)
                ->update($updateData);

            if ($soapResult->getCode() == "1033") {
                $responseData = [
                    "state" => false,
                    "code" => $soapResult->getCode(),
                    "description" => $soapResult->getDescription(),
                ];
            } else {
                return response()->json(["message" => $soapResult->getDescription()], 500);
            }
        }

        return response()->json($responseData);
    }

    public static function sendSumaryToSunat(string $fileName, DOMDocument $xml, string $idVenta, $empresa, int $correlativo, DateTime $currentDate)
    {
        $fileNameXml  = $fileName . '.xml';
        $path = 'sunat/' . $fileNameXml;

        // Storage::disk('files')->put($path, $xml->saveXML());
        Storage::put('files/'.$path, $xml->saveXML());
        Sunat::signDocument($fileNameXml);

        Sunat::createZip(
            Storage::path("files/sunat/" . $fileName . '.zip'),
            Storage::path("files/sunat/" . $fileNameXml),
            $fileNameXml
        );

        if (DB::table('empresa')->where('tipoEnvio', 1)->get()->isEmpty()) {
            $wdsl = Storage::path('wsdl/desarrollo/billService.wsdl');
        } else {
            $wdsl = Storage::path('wsdl/produccion/billService.wsdl');
        }

        $soapResult = new SoapResult($wdsl, $fileName);
        $soapResult->sendSumary(Sunat::xmlSendSummary(
            $empresa->documento,
            $empresa->usuarioSolSunat,
            $empresa->claveSolSunat,
            $fileName . '.zip',
            Sunat::generateBase64File(Storage::get('files/sunat/' . $fileName . '.zip'))
        ));

        $updateData = [
            "xmlSunat" => $soapResult->getCode(),
            "xmlDescripcion" => $soapResult->getDescription(),
            "correlativo" => $correlativo,
            "fechaCorrelativo" => $currentDate->format('Y-m-d'),
        ];

        if ($soapResult->isSuccess()) {
            if ($soapResult->isAccepted()) {
                $updateData["ticketConsultaSunat"] = $soapResult->getTicket();

                DB::table("venta")
                    ->where('idVenta', $idVenta)
                    ->update($updateData);

                $responseData = [
                    "state" => $soapResult->isSuccess(),
                    "accept" => $soapResult->isAccepted(),
                    "code" => $soapResult->getCode(),
                    "description" => $soapResult->getDescription(),
                ];
            } else {
                DB::table("venta")
                    ->where('idVenta', $idVenta)
                    ->update($updateData);

                $responseData = [
                    "state" => $soapResult->isSuccess(),
                    "code" => $soapResult->getCode(),
                    "description" => $soapResult->getDescription(),
                ];
            }
        } else {
            DB::table("venta")
                ->where('idVenta', $idVenta)
                ->update($updateData);

            return response()->json(["message" => $soapResult->getDescription()], 500);
        }

        return response()->json($responseData);
    }

    public static function getStatusToSunat($idVenta, $venta, $empresa, $fileName)
    {
        if (DB::table('empresa')->where('tipoEnvio', 1)->get()->isEmpty()) {
            $wdsl = Storage::path('wsdl/desarrollo/billService.wsdl');
        } else {
            $wdsl = Storage::path('wsdl/produccion/billService.wsdl');
        }

        $soapResult = new SoapResult($wdsl, $fileName);
        $soapResult->setTicket($venta->ticketConsultaSunat);
        $soapResult->sendGetStatus(Sunat::xmlGetStatus(
            $empresa->documento,
            $empresa->usuarioSolSunat,
            $empresa->claveSolSunat,
            $soapResult->getTicket()
        ));

        $updateData = [
            "xmlSunat" => "",
            "xmlDescripcion" => $soapResult->getDescription(),
        ];

        if ($soapResult->isSuccess()) {
            if (!$soapResult->isAccepted()) {
                if ($soapResult->getCode() == "2987"  || $soapResult->getCode() == "1032") {
                    $updateData["xmlSunat"] = "0";
                }
            }

            DB::table("venta")
                ->where('idVenta', $idVenta)
                ->update($updateData);

            $responseData = [
                "state" => $soapResult->isSuccess(),
                "accept" => $soapResult->isAccepted(),
                "code" => $soapResult->getCode(),
                "description" => $soapResult->getDescription()
            ];
        } else {
            return response()->json(["message" => $soapResult->getDescription()], 500);
        }

        return response()->json($responseData);
    }

    public static function sendDespatchAdvice(string $fileName, DOMDocument $xml, $idGuiaRemision, $guiaRemision, $empresa)
    {
        $fileNameXml  = $fileName . '.xml';
        $path = 'sunat/' . $fileNameXml;

        // Storage::disk('files')->put($path, $xml->saveXML());
        Storage::put('files/'.$path, $xml->saveXML());
        Sunat::signDocument($fileNameXml);

        Sunat::createZip(
            Storage::path("files/sunat/" . $fileName . '.zip'),
            Storage::path("files/sunat/" . $fileNameXml),
            $fileNameXml
        );

        $soapResult = new SoapResult('', $fileName);
        $soapResult->setConfigGuiaRemision(Storage::path("files/sunat/" . $fileName . '.zip'));
        $soapResult->sendGuiaRemision(
            [
                "NumeroDocumento" => $empresa->documento,
                "UsuarioSol" => $empresa->usuarioSolSunat,
                "ClaveSol" => $empresa->claveSolSunat,
                "IdApiSunat" => $empresa->idApiSunat,
                "ClaveApiSunat" => $empresa->claveApiSunat,
            ],
            [
                "numRucEmisor" => $empresa->documento,
                "codCpe" => $guiaRemision->codigo,
                "numSerie" => $guiaRemision->serie,
                "numCpe" => $guiaRemision->numeracion,
            ]
        );

        if ($soapResult->isSuccess()) {
            $updateData = [
                "xmlSunat" => $soapResult->getCode(),
                "xmlDescripcion" => $soapResult->getMessage(),
            ];
            if ($soapResult->isAccepted()) {
                $updateData += [
                    "xmlGenerado" => Sunat::getXmlSign(),
                    "numeroTicketSunat" => $soapResult->getTicket()
                ];
            }

            DB::table("guiaRemision")
                ->where('idGuiaRemision', $idGuiaRemision)
                ->update($updateData);

            $responseData = [
                "state" => $soapResult->isSuccess(),
                "accept" => $soapResult->isAccepted(),
                "code" => $soapResult->getCode(),
                "description" => $soapResult->getMessage()
            ];
        } else {
            if ($soapResult->getCode() == "1033") {
                $updateData = [
                    "xmlSunat" => "0",
                    "xmlDescripcion" => $soapResult->getMessage(),
                ];

                DB::table("guiaRemision")
                    ->where('idGuiaRemision', $idGuiaRemision)
                    ->update($updateData);

                $responseData = [
                    "state" => false,
                    "code" => $soapResult->getCode(),
                    "description" => $soapResult->getMessage()
                ];
            } else {
                return response()->json([
                    "message" => $soapResult->getMessage()
                ], 500);
            }
        }

        return response()->json($responseData);
    }

    public static function getStatusDespatchAdvice(string $fileName, $idGuiaRemision, $guiaRemision, $empresa, $ticket)
    {
        $soapResult = new SoapResult('', $fileName);
        $soapResult->setTicket($ticket);
        $soapResult->sendGuiaRemision(
            [
                "NumeroDocumento" => $empresa->documento,
                "UsuarioSol" => $empresa->usuarioSolSunat,
                "ClaveSol" => $empresa->claveSolSunat,
                "IdApiSunat" => $empresa->idApiSunat,
                "ClaveApiSunat" => $empresa->claveApiSunat,
            ],
            [
                "numRucEmisor" => $empresa->documento,
                "codCpe" => $guiaRemision->codigo,
                "numSerie" => $guiaRemision->serie,
                "numCpe" => $guiaRemision->numeracion,
            ]
        );

        if ($soapResult->isSuccess()) {
            $updateData = [
                "xmlSunat" => $soapResult->getCode(),
                "xmlDescripcion" => $soapResult->getMessage(),
            ];

            if ($soapResult->isAccepted()) {
                $updateData["codigoHash"] = $soapResult->getHashCode();
            }

            DB::table("guiaRemision")
                ->where('idGuiaRemision', $idGuiaRemision)
                ->update($updateData);

            $responseData = [
                "state" => $soapResult->isSuccess(),
                "accept" => $soapResult->isAccepted(),
                "code" => $soapResult->getCode(),
                "description" => $soapResult->getMessage()
            ];
        } else {
            if ($soapResult->getCode() == "1033") {
                $updateData = [
                    "xmlSunat" => "0",
                    "xmlDescripcion" => $soapResult->getMessage(),
                ];

                DB::table("guiaRemision")
                    ->where('idGuiaRemision', $idGuiaRemision)
                    ->update($updateData);

                $responseData = [
                    "state" => false,
                    "code" => $soapResult->getCode(),
                    "description" => $soapResult->getMessage()
                ];
            } else {
                return response()->json([
                    "message" => $soapResult->getMessage()
                ], 500);
            }
        }

        return response()->json($responseData);
    }
}
