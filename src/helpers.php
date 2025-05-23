<?php

declare(strict_types=1);

namespace esperecyan\vrchat_posters;

use RuntimeException;
use DateTimeImmutable;
use DateTimeZone;
use Intervention\Image\{
    Image,
    ImageManagerStatic,
};
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

const GOOGLE_DRIVE_POSTER_FILE_NAME_ID_PAIRS = [
    // v1
    // Googleドライブが動画URLのホワイトリストから外されたが、v1ではGoogleドライブのみを利用していたため、そのプレハブを利用している古いワールドの機能を維持するため、同期を継続する
    'posters-quest1.mp4' => '1_EACTaE3k3zkA9kN_tSCMIaBQ85LkD8k',
];

const QUEST1_TEXTURE_SIZE = 2000;

/** @var int 横に何枚並べるか。 */
const HORIZONTALLY_COUNT = 4;
/** @var float 幅から高さを算出するときの除数。 */
const ASPECT_RATIO = M_SQRT1_2;
/** @var GitHub APIにアクセスするときのuser-agentヘッダ (同ヘッダがないとエラーが返るため)。 */
const USER_AGENT = 'esperecyan/vrchat-posters';
/** 更新日時を保存するときのタイムゾーン (保存したファイルの可読性のため)。 */
const TIME_ZONE = 'Asia/Tokyo';

/**
 * URLからデータを取得します。
 * @param ...$arguments `file_get_contents()` 関数へ渡す引数。
 * @throws RuntimeException 取得に失敗したとき。
 * @return string バイナリデータ。
 */
function fetchFile(...$arguments)
{
    $file = file_get_contents(...$arguments);
    if ($file === false) {
        throw new RuntimeException("<$arguments[0]> からのデータ取得に失敗しました。");
    }
    return $file;
}

/**
 * GitHubリポジトリの単一ファイルの最新コミット日時を取得します。
 * @param string $repository ユーザー名 (組織名) を含む対象のリポジトリ名。
 * @param string $branch 対象のブランチ名。
 * @param string $path 「/」で始まるパス。
 * @return DateTimeImmutable `format()` メソッド用に、`TIME_ZONE` のタイムゾーンが設定されています。
 */
function fetchGitHubFileUpdateDateTime(string $repository, string $branch, string $path): DateTimeImmutable
{
    $githubToken = getenv('GITHUB_TOKEN');
    return (new DateTimeImmutable(json_decode(fetchFile(
        "https://api.github.com/repos/$repository/commits?"
            . http_build_query([ 'sha' => $branch, 'path' => $path, 'per_page' => '1' ]),
        context: stream_context_create([ 'http' => [ 'header' => [
            'user-agent: ' . USER_AGENT,
            $githubToken ? 'authorization: Bearer ' . $githubToken : null,
        ] ] ])
    ))[0]->commit->committer->date))->setTimezone(new DateTimeZone(TIME_ZONE));
}

/**
 * GitHubの単一ファイルを取得します。
 * @param string $repository ユーザー名 (組織名) を含む対象のリポジトリ名。
 * @param string $branch 対象のブランチ名。
 * @param string $path 「/」で始まるパス。
 * @return string バイナリデータ。
 */
function fetchGitHubFile(string $repository, string $branch, string $path): string
{
    return fetchFile("https://raw.githubusercontent.com/$repository/$branch$path");
}

/**
 * `Google\Service\Drive` を取得します。
 * @return Drive
 */
function getGoogleDrive(): Drive
{
    $client = new Client();
    $secretKey = getenv('GOOGLE_SERVICE_ACCOUNT_SECRET_KEY');
    $client->setAuthConfig(json_decode($secretKey
        ? $secretKey
        : /* ローカルデバッグ */ file_get_contents(__DIR__ . '/../.googleServiceAccountSecretKey.json'), associative: true));
    $client->addScope(Drive::DRIVE);
    return new Drive($client);
}

/**
 * Googleドライブの単一ファイルの更新日時を取得します。
 * @param Drive $drive
 * @param string $id ファイルのID。
 * @return DateTimeImmutable `format()` メソッド用に、`TIME_ZONE` のタイムゾーンが設定されています。
 */
function fetchGoogleDriveFileUpdateDateTime(Drive $drive, $id): DateTimeImmutable
{
    return (new DateTimeImmutable($drive->files->get($id, [ 'fields' => 'modifiedTime' ])->modifiedTime))
        ->setTimezone(new DateTimeZone(TIME_ZONE));
}

/**
 * Googleドライブの単一ファイルを取得します。
 * @param Drive $drive
 * @param string $id ファイルのID。
 * @return string バイナリデータ。
 */
function fetchGoogleDriveFile(Drive $drive, $id): string
{
    return $drive->files->get($id, [ 'alt' => 'media' ])->getBody()->getContents();
}

/**
 * Googleドライブの単一ファイルを更新します。
 * @param Drive $drive
 * @param string $id ファイルのID。
 * @param string $data バイナリデータ。
 */
function putFileToGoogleDrive(Drive $drive, string $id, string $data): void
{
    $drive->files->update($id, new DriveFile(), [ 'data' => $data ]);
}

/**
 * URLで指定された単一ファイルの更新日時を取得します。
 * @param string $url
 * @return DateTimeImmutable `format()` メソッド用に、`TIME_ZONE` のタイムゾーンが設定されています。
 */
function fetchURLUpdateDateTime(string $url): DateTimeImmutable
{
    return (new DateTimeImmutable(array_change_key_case(get_headers(
        $url,
        associative: true,
        context: stream_context_create([ 'http' => [ 'method' => 'HEAD' ] ])
    ))['last-modified']))->setTimezone(new DateTimeZone(TIME_ZONE));
}

/**
 * 画像へポスターを重ねます。
 * @param Image $image
 * @param stdClass[] $posters 各ポスターに関するデータ。
 * @param bool $video VideoPlayer向けの旧バージョンなら `true`。
 */
function combinePosters(Image $image, array $posters, bool $video): void
{
    foreach ($posters as $poster) {
        $rect = $video ? ($poster->rectForVideo ?? $poster->rect) : $poster->rect;
        $updatedImage = ImageManagerStatic::make($poster->updatedImage);
        if ($updatedImage->getWidth() !== $rect->width) {
            $updatedImage->resize($rect->width, $rect->height);
        }
        $image->insert($updatedImage, 'top-left', $rect->x, $rect->y);
    }
}

/**
 * MP4の最初のフレームを抽出します。
 * @param string $video MP4のバイナリデータ。
 * @return string 画像のバリナリデータ。
 */
function extractImageFromVideo(string $video): string
{
    $temporaryVideoPath = tempnam(sys_get_temp_dir(), prefix: '') . '.mp4';
    $temporaryImagePath = tempnam(sys_get_temp_dir(), prefix: '') . '.png';
    try {
        file_put_contents($temporaryVideoPath, $video);
        exec('ffmpeg -i ' . escapeshellarg($temporaryVideoPath) . ' -frames:v 1 '
            . escapeshellarg($temporaryImagePath), $output, $returnVar);
        if ($returnVar > 0) {
            throw new RuntimeException(implode("\n", $output), $returnVar);
        }
        return file_get_contents($temporaryImagePath);
    } finally {
        foreach ([ $temporaryVideoPath, $temporaryImagePath ] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}

/**
 * 画像を2フレームの動画へ変換します。
 * @param string $imagePath
 * @param string $videoPath
 */
function convertImageToVideo(string $imagePath, string $videoPath): void
{
    exec('ffmpeg -y -framerate 60 -loop 1 -t 0.24 -i ' . escapeshellarg($imagePath)
        . ' -vcodec libx264 -pix_fmt yuv420p -r 60 ' . escapeshellarg($videoPath), $output, $returnVar);
    if ($returnVar > 0) {
        throw new RuntimeException(implode("\n", $output), $returnVar);
    }
}

/**
 * 動画の解像度を変更します。
 * @param string $sourcePath
 * @param string $destinationPath
 * @param int $width 変換後の幅 (ピクセル数)。
 */
function convertVideoResolution(string $sourcePath, string $destinationPath, int $width): void
{
    exec('ffmpeg -y -i ' . escapeshellarg($sourcePath)
        . ' -vf ' . escapeshellarg("scale=$width:-1")
        . ' ' . escapeshellarg($destinationPath), $output, $returnVar);
    if ($returnVar > 0) {
        throw new RuntimeException(implode("\n", $output), $returnVar);
    }
}
