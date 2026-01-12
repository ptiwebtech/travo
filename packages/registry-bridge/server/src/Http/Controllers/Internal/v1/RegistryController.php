<?php

namespace Fleetbase\RegistryBridge\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Resources\Category as CategoryResource;
use Fleetbase\Models\Category;
use Fleetbase\RegistryBridge\Models\RegistryExtension;
use Illuminate\Http\Request;

class RegistryController extends Controller
{
    /**
     * Retrieve a collection of categories that have registry extensions.
     *
     * This method fetches categories that have associated registry extensions
     * and meet the specified criteria (core_category equals 1 and 'for' equals
     * 'extension_category'). The retrieved categories are then wrapped using
     * the CategoryResource and returned as a collection of CategoryResource.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     *                                                                     A collection of CategoryResource objects representing the categories
     */
    public function categories()
    {
        $categories = Category::whereHas('registryExtensions')->where(['core_category' => 1, 'for' => 'extension_category'])->get();

        CategoryResource::wrap('categories');

        return CategoryResource::collection($categories);
    }

    /**
     * Retrieve a list of installed engines for the current company session.
     *
     * This method fetches registry extensions that have been installed by the
     * company identified in the current session. It disables caching for the
     * query, filters the results based on the company's UUID stored in the session,
     * and maps the retrieved extensions to extract the 'package.json' metadata.
     * The result is returned as a JSON response.
     *
     * @return \Illuminate\Http\JsonResponse
     *                                       A JSON response containing a list of installed engines with their metadata
     */
    public function getInstalledEngines()
    {
        $installedExtensions = RegistryExtension::disableCache()->whereHas('installs', function ($query) {
            $query->where('company_uuid', session('company'));
        })->get()->map(function ($extension) {
            return $extension->currentBundle->meta['package.json'] ?? [];
        });

        return response()->json($installedExtensions);
    }

    /**
     * Lookup and retrieve package information based on the provided package name.
     *
     * This method handles a request to lookup a package by its name. It utilizes the `RegistryExtension::lookup` method to find the
     * corresponding registry extension. If no extension is found or if the extension does not have valid package or composer data,
     * an error response is returned.
     *
     * If a valid extension and its associated bundle are found, the function extracts the package and composer names from the
     * `package.json` and `composer.json` metadata. These names are then returned in a JSON response.
     *
     * @param Request $request the incoming HTTP request containing the 'package' input parameter
     *
     * @return \Illuminate\Http\JsonResponse a JSON response containing the package and composer names if found, or an error message otherwise
     */
    public function lookupPackage(Request $request)
    {
        $packageName       = $request->input('package');
        $registryExtension = RegistryExtension::lookup($packageName);
        if (!$registryExtension) {
            return response()->error('No extension found by this name for install');
        }

        if (!$registryExtension->currentBundle) {
            return response()->error('No valid package data found for this extension install');
        }

        $packageJson = $registryExtension->currentBundle->meta['package.json'];
        if (!$packageJson) {
            return response()->error('No valid package data found for this extension install');
        }

        $composerJson = $registryExtension->currentBundle->meta['composer.json'];
        if (!$composerJson) {
            return response()->error('No valid package data found for this extension install');
        }

        $packageJsonName  = data_get($packageJson, 'name');
        $composerJsonName = data_get($composerJson, 'name');

        return response()->json([
            'npm'      => $packageJsonName,
            'composer' => $composerJsonName,
        ]);
    }
}
