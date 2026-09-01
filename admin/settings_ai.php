<?php
require_once "includes/inc_all_admin.php";
 ?>

    <div class="card card-dark">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-robot me-2"></i>AI</h3>
        </div>
        <div class="card-body">

            <p><i>Provider, model and prompt are configured under <a href="ai_provider.php">AI Providers</a>. The settings below control the company-wide AI behaviour used by features such as ticket summaries, document template generation and the reword tool.</i></p>

            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label>AI Features</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-robot"></i></span>
                        </div>
                        <select class="form-control" name="config_ai_enable">
                            <option <?php if ($config_ai_enable == 0) { echo "selected"; } ?> value="0">Disabled</option>
                            <option <?php if ($config_ai_enable == 1) { echo "selected"; } ?> value="1">Enabled</option>
                        </select>
                    </div>
                    <small class="form-text text-muted">Turn all AI-assisted features on or off for this company.</small>
                </div>

                <div class="form-group">
                    <label>Max Input Characters</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-text-width"></i></span>
                        </div>
                        <input type="number" class="form-control" name="config_ai_max_input_chars" min="1000" max="100000" step="500" value="<?php echo intval($config_ai_max_input_chars); ?>">
                    </div>
                    <small class="form-text text-muted">Input sent to the AI provider is truncated to this many characters per message (default 12000).</small>
                </div>

                <div class="form-group">
                    <label>Request Timeout (seconds)</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                        </div>
                        <input type="number" class="form-control" name="config_ai_timeout_seconds" min="5" max="300" step="1" value="<?php echo intval($config_ai_timeout_seconds); ?>">
                    </div>
                    <small class="form-text text-muted">How long to wait for the AI provider before giving up (default 25).</small>
                </div>

                <hr>

                <button type="submit" name="edit_ai_settings" class="btn btn-primary text-bold float-end"><i class="fas fa-check me-2"></i>Save</button>

            </form>
        </div>
    </div>

<?php
require_once "../includes/footer.php";
