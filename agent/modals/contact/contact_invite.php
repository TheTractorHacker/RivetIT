<div class="modal" id="contactInviteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark">
                <h5 class="modal-title"><i class="fas fa-fw fa-user-plus me-2"></i>Invite Contact</h5>
                <button type="button" class="close text-white" data-bs-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>

            <form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">

                <div class="modal-body">

                    <div class="form-group">
                        <label>Email</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span>
                            </div>
                            <input type="email" class="form-control" name="email" placeholder="Email Address" maxlength="200">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Welcome Letter</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-fw fa-envelope-open-text"></i></span>
                            </div>
                            <select class="form-control select2" name="welcome_letter">
                                <option value="1">- Select One -</option>
                                <option value="2">Standard</option>
                                <option value="3">Big Wig</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <textarea class="form-control" rows="8" name="notes" placeholder="Enter some notes"><?php echo $contact_notes; ?></textarea>
                    </div>

                    <div class="form-row">

                        <div class="col-md-4">
                            <div class="form-group">
                                <div class="form-check form-check">
                                    <input type="checkbox" class="form-check-input" id="contactInviteImportantCheckbox" name="contact_important" value="1" >
                                    <label class="form-check-label" for="contactInviteImportantCheckbox">Important</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <div class="form-check form-check">
                                    <input type="checkbox" class="form-check-input" id="contactInviteBillingCheckbox" name="contact_billing" value="1" >
                                    <label class="form-check-label" for="contactInviteBillingCheckbox">Billing</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <div class="form-check form-check">
                                    <input type="checkbox" class="form-check-input" id="contactInviteTechnicalCheckbox" name="contact_technical" value="1" >
                                    <label class="form-check-label" for="contactInviteTechnicalCheckbox">Technical</label>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="submit" name="invite_contact" class="btn btn-primary text-bold"><i class="fas fa-paper-plane me-2"></i>Send Invite</button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
                </div>

            </form>

        </div>
    </div>
</div>
