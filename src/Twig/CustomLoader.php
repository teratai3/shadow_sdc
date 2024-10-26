<?php

namespace Drupal\shadow_sdc\Twig;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileUrlGenerator;
use Drupal\Core\Template\Loader\FilesystemLoader;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
// Use Twig\Loader\FilesystemLoader;.
use Twig\Source;

/**
 * カスタムローダークラス.
 */
class CustomLoader extends FilesystemLoader {
  /**
   * テーママネージャー.
   *
   * @var \Drupal\Core\Theme\ThemeManagerInterface
   */
  protected $themeManager;

  /**
   * ファイルURLジェネレータサービス.
   *
   * @var \Drupal\Core\File\FileUrlGenerator
   */
  protected $fileUrlGenerator;

  public function __construct(
    $paths,
    ModuleHandlerInterface $module_handler,
    ThemeHandlerInterface $theme_handler,
    ThemeManagerInterface $themeManager,
    FileUrlGenerator $fileUrlGenerator,
    array $twig_config = [],
  ) {
    parent::__construct($paths, $module_handler, $theme_handler, $twig_config);
    $this->themeManager = $themeManager;
    $this->fileUrlGenerator = $fileUrlGenerator;
  }

  /**
   * {@inheritDoc}
   */
  public function getSourceContext(string $name): Source {

    if (NULL === $path = $this->findTemplate($name)) {
      return new Source('', $name, '');
    }

    $active_theme = $this->themeManager->getActiveTheme();
    $template_content = file_get_contents($path);
    $template_content = $this->wrapIncludeTag($template_content, $active_theme);

    return new Source($template_content, $name, $path);
  }

  /**
   * コンポーネントをラップするメソッド.
   */
  protected function wrapIncludeTag($content, ActiveTheme $active_theme) {
    // $pattern = '/(\{\{\s*include\([\s\S]*?\)\s*(\|[\s\S]*?)?\}\})/s';
    // 正規表現でテーマ名:コンポーネント名形式の include タグを検索.
    $theme_name = $active_theme->getName();

    $pattern = '/
        (\{\{\s*include      # {{ include の部分
        \(\s*               # ( の後の空白
        [\'"]' . preg_quote($theme_name, '/') . ':(.*?)["\']  # "$theme_name:コンポーネント名" の部分
        \s*,\s*             # カンマの前後の空白
        (.*?)               # パラメータ部分をキャプチャ
        \)\s*               # ) の後の空白
        (\|[\s\S]*?)?       # オプショナルなフィルター部分
        \}\})               # }} の部分
    /sx';

    preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
      // 元の一致部分.
      $full_match = $match[0];
      // コンポーネント名.
      $component_name = $match[2];
      // パラメーター.
      $parameters = $match[3];
      // フィルター（存在する場合）.
      $filters = $match[4] ?? '';

      $components_path = \Drupal::service('file_system')->realpath($active_theme->getPath() . '/components/' . $component_name);
      $components_css = $components_path . '/' . $component_name . ".component.css";
      // 同じ名前のcssファイルがあれば shadow dom化する.
      if (file_exists($components_css)) {
        $css_url = $this->fileUrlGenerator->generateAbsoluteString($active_theme->getPath() . '/components/' . $component_name . '/' . $component_name . ".component.css");
        $replacement = "<{$component_name}-component><template shadowrootmode='open'>";
        $replacement .= "<link rel='stylesheet' href='{$css_url}'>";
        $replacement .= "{{ include('{$theme_name}:{$component_name}', {$parameters}){$filters} }}";
        $replacement .= "</template></{$component_name}-component>";
        $content = str_replace($full_match, $replacement, $content);
      }
    }

    return $content;
  }

}
