<?php
/**
 * @OA\Info(
 *     title="seaofsea API",
 *     version="1.0",
 *     description="seaofsea projesi için API dokümantasyonu"
 * )
 */
/**
 * @OA\SecurityScheme(
 *     securityScheme="BearerToken",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 */
 /**
 * @OA\Get(
 *     path="/api/users",
 *     summary="User List",
 *     security={{"BearerToken":{}}},
 *     @OA\Response(response=200, description="Success")
 * )
 */

?>