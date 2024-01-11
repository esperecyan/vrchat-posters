<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Intervention\Image\ImageManagerStatic as Image;
use const esperecyan\vrchat_posters\{
    GOOGLE_DRIVE_POSTER_FILE_NAME_ID_PAIRS,
    QUEST1_TEXTURE_SIZE,
};
use function esperecyan\vrchat_posters\{
    fetchFile,
    fetchGitHubFileUpdateDateTime,
    fetchGitHubFile,
    getGoogleDrive,
    fetchGoogleDriveFileUpdateDateTime,
    fetchGoogleDriveFile,
    putFileToGoogleDrive,
    fetchURLUpdateDateTime,
    extractImageFromVideo,
    combinePosters,
    convertImageToVideo,
    convertVideoResolution,
};

$rootPath = __DIR__ . '/../';
$postersDataFolderPath = $rootPath . 'posters-data/';
$pagesFolderPath = $rootPath . '_site/';

$posterUpdateDatesPath = $postersDataFolderPath . 'posters-update-dates.json';

/** @var stdClass[] 各ポスターに関するデータ。 */
$posters = json_decode(file_get_contents(__DIR__ . '/../posters.json'));
/** @var string[] 自動更新ポスターまとめのバージョン接尾辞の一覧。 */
$allVersionSuffixes = array_unique(array_merge(...array_column($posters, 'versionSuffixes')));

/** @var bool 2回目以降の実行なら `true`。 */
$cacheExisting = count(array_filter(
    $allVersionSuffixes,
    fn(string $suffix): bool => file_exists("{$postersDataFolderPath}posters-cache$suffix.png"),
)) === count($allVersionSuffixes);

// 更新の確認・画像の取得
/** @var DateTimeImmutable[] ポスターIDと前回の更新日時の連想配列。 */
$idDateTimePairs = $cacheExisting ? array_map(
    fn($date) => new DateTimeImmutable($date),
    json_decode(file_get_contents($posterUpdateDatesPath), associative: true, flags: JSON_THROW_ON_ERROR)
) : [ ];
echo '::debug::$idDateTimePairs: 更新確認前: ' . json_encode($idDateTimePairs, JSON_PRETTY_PRINT) . "\n";
/** @var bool[] グループと更新されているか否かの連想配列。 */
$groupUpdatedPairs = [ ];
$drive = getGoogleDrive();
/** @var \stdClass $information 必須の「id」「type」プロパティと、typeに応じて他のプロパティを持つオブジェクト。 */
foreach ($posters as $information) {
    $updated = null;

    if (!$cacheExisting) {
        // 初回の実行なら
        $updated = true;
    }

    if (!$updated && isset($information->group) && isset($groupUpdatedPairs[$information->group])) {
        // 同じグループで既に更新チェックを行ったポスターがあれば
        if ($groupUpdatedPairs[$information->group]) {
            $updated = true;
        } else {
            continue;
        }
    }

    // ポスター更新日時の取得
    if (empty($information->group) || empty($groupUpdatedPairs[$information->group])) {
        // グループに所属していない、または同じグループで既に更新チェックを行ったポスターがなければ
        switch ($information->type) {
            case 'github':
                $updateDateTime
                    = fetchGitHubFileUpdateDateTime($information->repository, $information->branch, $information->path);
                break;

            case 'google-drive':
                $updateDateTime = fetchGoogleDriveFileUpdateDateTime($drive, $information->fileId);
                break;

            case 'url':
                $updateDateTime = fetchURLUpdateDateTime($information->url);
                break;
        }
    }

    if ($updated === null) {
        $updated = $updateDateTime != $idDateTimePairs[$information->group ?? $information->id];
    }

    if (isset($information->group)) {
        $groupUpdatedPairs[$information->group] = $updated;
    }

    if (!$updated) {
        continue;
    }

    echo "::notice::更新: $information->id: "
        . (isset($idDateTimePairs[$information->group ?? $information->id])
            ? $idDateTimePairs[$information->group ?? $information->id]->format(DateTimeInterface::ATOM)
            : 'null')
        . " → {$updateDateTime->format(DateTimeInterface::ATOM)}\n";

    if ($updateDateTime) {
        $idDateTimePairs[$information->group ?? $information->id] = $updateDateTime;
    }

    // ポスター画像データの取得
    switch ($information->type) {
        case 'github':
            $information->updatedImage = fetchGitHubFile($information->repository, $information->path);
            break;

        case 'google-drive':
            $information->updatedImage = fetchGoogleDriveFile($drive, $information->fileId);
            break;

        case 'url':
            $information->updatedImage = fetchFile($information->url);
            break;
    }
    if (isset($information->fileType) && $information->fileType === 'mp4') {
        $information->updatedImage = extractImageFromVideo($information->updatedImage);
    }
}

echo '::debug::$idDateTimePairs: 更新確認後: ' . json_encode($idDateTimePairs, JSON_PRETTY_PRINT) . "\n";

$updatedPosters = array_filter($posters, fn(stdClass $information): bool => isset($information->updatedImage));
if (!$updatedPosters) {
    // 更新されたポスターが無ければ
    echo "::notice::更新なし\n";
    exit;
}

if (!file_exists($postersDataFolderPath)) {
    mkdir($postersDataFolderPath);
}

// 更新日時の保存
file_put_contents($posterUpdateDatesPath, json_encode(
    array_map(fn($dateTime) => $dateTime->format(DateTimeInterface::RFC3339_EXTENDED), $idDateTimePairs),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
));

foreach ($allVersionSuffixes as $versionSuffix) {
    $updatedPostersBelongsToCurrentVersionSuffix = array_filter(
        $updatedPosters,
        fn(stdClass $poster): bool => in_array($versionSuffix, $poster->versionSuffixes, strict: true),
    );

    /**
     * @var bool VideoPlayer向けの旧バージョンなら `true`。
     */
    $video = in_array($versionSuffix, [ "", "-v4" ]);

    // キャッシュ画像を作成
    $cacheImagePath = "{$postersDataFolderPath}posters-cache$versionSuffix.png";
    $texture = $video
        ? Image::make($cacheExisting ? $cacheImagePath : __DIR__ . '/../posters-template.png')
        : ($cacheExisting ? Image::make($cacheImagePath) : Image::canvas(... array_reduce(
            $updatedPostersBelongsToCurrentVersionSuffix,
            function (array $canvasParameters, stdClass $poster): array {
                if ($poster->rect->x + $poster->rect->width > $canvasParameters['width']) {
                    $canvasParameters['width'] = $poster->rect->x + $poster->rect->width;
                }
                if ($poster->rect->y + $poster->rect->height > $canvasParameters['height']) {
                    $canvasParameters['height'] = $poster->rect->y + $poster->rect->height;
                }
                return $canvasParameters;
            },
            [ 'width' => 0, 'height' => 0, 'background' => '#000000' ],
        )));
    combinePosters($texture, $updatedPostersBelongsToCurrentVersionSuffix, $video);
    $texture->save($cacheImagePath);

    if (!file_exists($pagesFolderPath)) {
        mkdir($pagesFolderPath);
    }

    if ($video) {
        // PC、Quest2用の動画を作成・Googleドライブのファイルを更新
        $videoFileName = "posters$versionSuffix.mp4";
        convertImageToVideo($cacheImagePath, $pagesFolderPath . $videoFileName);

        // 初代Quest用の動画を作成・Googleドライブのファイルを更新
        $quest1VideoFileName = "posters$versionSuffix-quest1.mp4";
        convertVideoResolution(
            $pagesFolderPath . $videoFileName,
            $pagesFolderPath . $quest1VideoFileName,
            QUEST1_TEXTURE_SIZE,
        );

        // Googleドライブのファイルを更新
        if (isset(GOOGLE_DRIVE_POSTER_FILE_NAME_ID_PAIRS[$quest1VideoFileName])) {
            putFileToGoogleDrive(
                $drive,
                GOOGLE_DRIVE_POSTER_FILE_NAME_ID_PAIRS[$quest1VideoFileName],
                file_get_contents($pagesFolderPath . $quest1VideoFileName),
            );
        }
    } else {
        copy($cacheImagePath, "{$pagesFolderPath}posters$versionSuffix.png");
    }
}

copy(__DIR__ . '/../posters-v5-vrchat-event-calendar.png', $pagesFolderPath . 'posters-v5-vrchat-event-calendar.png');

// 出力
$githubOutput = getenv('GITHUB_OUTPUT');
if ($githubOutput) {
    file_put_contents($githubOutput, "\nupdated=on", FILE_APPEND);
}
