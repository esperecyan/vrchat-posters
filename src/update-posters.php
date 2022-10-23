<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Intervention\Image\ImageManagerStatic as Image;
use const esperecyan\vrchat_posters\{
    GOOGLE_DRIVE_POSTER_FILE_ID,
    GOOGLE_DRIVE_POSTER_QUEST1_FILE_ID,
    QUEST1_TEXTURE_SIZE,
};
use function esperecyan\vrchat_posters\{
    fetchGitHubFileUpdateDateTime,
    fetchGitHubFile,
    getGoogleDrive,
    fetchGoogleDriveFileUpdateDateTime,
    fetchGoogleDriveFile,
    putFileToGoogleDrive,
    fetchURLUpdateDateTime,
    combinePosters,
    convertImageToVideo,
};

$rootPath = __DIR__ . '/../';
$postersDataFolderPath = $rootPath . 'posters-data/';
$pagesFolderPath = $rootPath . '_site/';

$posterUpdateDatesPath = $postersDataFolderPath . 'posters-update-dates.json';
$cacheImagePath = $postersDataFolderPath . 'posters-cache.png';

/** @var bool 2回目以降の実行なら `true`。 */
$cacheExisting = file_exists($posterUpdateDatesPath);

// 更新の確認・画像の取得
/** @var DateTimeImmutable[] ポスターIDと前回の更新日時の連想配列。 */
$idDateTimePairs = $cacheExisting ? array_map(
    fn($date) => new DateTimeImmutable($date),
    json_decode(file_get_contents($posterUpdateDatesPath), associative: true, flags: JSON_THROW_ON_ERROR)
) : [ ];
echo '::debug::$idDateTimePairs: 更新確認前: ' . json_encode($idDateTimePairs, JSON_PRETTY_PRINT);
/** @var (?string)[] ポスター配置順の、更新された画像のバイナリデータ。 */
$posters = [ ];
/** @var bool[] グループと更新されているか否かの連想配列。 */
$groupUpdatedPairs = [ ];
$drive = getGoogleDrive();
/** @var \stdClass $information 必須の「id」「type」プロパティと、typeに応じて他のプロパティを持つオブジェクト。 */
foreach (json_decode(file_get_contents(__DIR__ . '/../posters.json')) as $information) {
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
            $posters[] = null;
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
        $posters[] = null;
        continue;
    }

    echo "::notice::更新: $information->id: "
        . $idDateTimePairs[$information->group ?? $information->id]?->format(DateTimeInterface::ATOM)
        . " → {$updateDateTime->format(DateTimeInterface::ATOM)}\n";

    if ($updateDateTime) {
        $idDateTimePairs[$information->group ?? $information->id] = $updateDateTime;
    }

    // ポスター画像データの取得
    switch ($information->type) {
        case 'github':
            $posters[] = fetchGitHubFile($information->repository, $information->path);
            break;

        case 'google-drive':
            $posters[] = fetchGoogleDriveFile($drive, $information->fileId);
            break;

        case 'url':
            $posters[] = file_get_contents($information->url);
            break;
    }
}

echo '::debug::$idDateTimePairs: 更新確認後: ' . json_encode($idDateTimePairs, JSON_PRETTY_PRINT);

if (!array_filter($posters)) {
    // 更新されたポスターが無ければ
    echo "::notice::更新なし\n";
    exit;
}

// 更新日時の保存
file_put_contents($posterUpdateDatesPath, json_encode(
    array_map(fn($dateTime) => $dateTime->format(DateTimeInterface::RFC3339_EXTENDED), $idDateTimePairs),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
));

// キャッシュ画像、PC、Quest2用の動画を作成
$texture = Image::make($cacheExisting ? $cacheImagePath : __DIR__ . '/../posters-template.png');
combinePosters($texture, $posters);
$texture->save($cacheImagePath);
if (!file_exists($pagesFolderPath)) {
mkdir($pagesFolderPath);
}
convertImageToVideo($cacheImagePath, $pagesFolderPath . 'posters.mp4');

// 初代Quest用の動画を作成
convertImageToVideo($cacheImagePath, $pagesFolderPath . 'posters-quest1.mp4', QUEST1_TEXTURE_SIZE);

// Googleドライブのファイルを更新
putFileToGoogleDrive($drive, GOOGLE_DRIVE_POSTER_FILE_ID, file_get_contents($pagesFolderPath . 'posters.mp4'));
putFileToGoogleDrive(
    $drive,
    GOOGLE_DRIVE_POSTER_QUEST1_FILE_ID,
    file_get_contents($pagesFolderPath . 'posters-quest1.mp4')
);

// 出力
file_put_contents(getenv('GITHUB_OUTPUT'), "\nupdated=on", FILE_APPEND);
